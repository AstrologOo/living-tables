<?php

namespace App\Console\Support;

use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\RunBudgetExceededException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Exceptions\LockLostException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\NodeRunStateService;
use App\Modules\Graph\Services\NotificationService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Graph\ValueObjects\StoreResult;
use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Support\PublicErrorMessage;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Runs one node end to end: claim, lock, pipeline, commit, wake-up of the children. Lives outside the modules
 * because it joins Graph and Execution.
 */
class NodeRecalculator
{
    private readonly FailedRunReporter $failures;

    public function __construct(
        private readonly SchedulerService $scheduler,
        private readonly ResultStore $resultStore,
        private readonly PipelineRunner $pipelineRunner,
        private readonly NodeEventPublisher $eventPublisher,
        private readonly int $lockTtlSeconds = 300,
        private readonly NotificationService $notifications = new NotificationService,
        // Longest a run may take, in seconds.
        private readonly int $maxRunSeconds = 1800,
        private readonly NodeRunStateService $runState = new NodeRunStateService,
    ) {
        $this->failures = new FailedRunReporter($notifications);
    }

    /**
     * Processes ready nodes until none remain, trying each at most once per pass.
     *
     * @param  ?Closure(): bool  $stopRequested  checked before each claim
     * @return int how many nodes were processed
     */
    public function drainReadyNodes(ExecutionContext $ctx, ?Closure $stopRequested = null): int
    {
        $count = 0;
        $skippedIds = [];

        while (! ($stopRequested !== null && $stopRequested()) && $node = $this->scheduler->claimReadyNode($skippedIds)) {
            try {
                if ($this->processNode($node, $ctx)) {
                    $count++;
                } else {
                    $skippedIds[] = $node->id;
                }
            } catch (PipelineException $e) {
                $this->handleFailure($node, $e);
                $skippedIds[] = $node->id;
            }
        }

        return $count;
    }

    /**
     * Claims and processes at most one ready node.
     */
    public function claimAndProcess(ExecutionContext $ctx): NodeProcessingResult
    {
        $node = $this->scheduler->claimReadyNode();

        if ($node === null) {
            return NodeProcessingResult::nothingReady();
        }

        try {
            $this->processNode($node, $ctx);

            return NodeProcessingResult::succeeded($node);
        } catch (PipelineException $e) {
            $this->handleFailure($node, $e);

            return NodeProcessingResult::failed($node, $e);
        }
    }

    /**
     * Permanent failures go straight to `failed`; others are retried after a delay, up to a limit.
     */
    private function handleFailure(Node $node, PipelineException $e): void
    {
        if ($this->isSchemaDrift($e)) {
            $this->markSchemaDrift($node, $e->getPrevious());

            return;
        }

        if ($this->isPermanent($e)) {
            $this->markFailed($node, $e);

            return;
        }

        Log::warning("Node {$node->id} run failed, retryable: {$e->getMessage()}");

        if ($this->runState->retryLater($node) === 'failed') {
            $this->failures->failed($node, PublicErrorMessage::for($e));
        }
    }

    /**
     * Whether retrying the same config on the same source is pointless. Network errors, 429, 5xx, LLM and unknown
     * errors are retried.
     */
    private function isPermanent(PipelineException $e): bool
    {
        $cause = $e->getPrevious();

        // A subclass of SsrfBlockedException, so checked first.
        if ($cause instanceof HostUnresolvedException) {
            return false;
        }

        if ($cause instanceof RequestException) {
            $status = $cause->response->status();

            return $e->op === 'fetch' && $status >= 400 && $status < 500 && $status !== 429;
        }

        // `map` does no I/O, so its errors come from the formula or data. Elsewhere, Error and LogicException mean a
        // bad config or a bug.
        return $e->op === 'map'
            || $cause instanceof \Error
            || $cause instanceof \LogicException
            || $cause instanceof UnknownOperationException
            // Limits and the run budget.
            || $cause instanceof LimitExceededException
            || $cause instanceof RunBudgetExceededException
            || $cause instanceof SsrfBlockedException
            || $cause instanceof ResponseTooLargeException;
    }

    private function isSchemaDrift(PipelineException $e): bool
    {
        return $e->getPrevious() instanceof SchemaDriftException;
    }

    /**
     * Marks the node `failed`, or `stale` if newer data arrived during the run; nothing if the claim was lost.
     * Children are untouched.
     */
    public function markFailed(Node $node, Throwable $e): void
    {
        Log::error("Node {$node->id} run failed: {$e->getMessage()}");

        $status = $this->runState->fail($node, 'failed');

        if ($status === 'failed') {
            $this->failures->failed($node, PublicErrorMessage::for($e));
        } elseif ($status !== null) {
            $this->failures->record($node, 'failed');
        }
    }

    /**
     * Halts the node for a human to fix the column mapping; same claim and newer-data rules as markFailed().
     */
    public function markSchemaDrift(Node $node, SchemaDriftException $e): void
    {
        Log::warning("Node {$node->id} schema drift: {$e->getMessage()}", [
            'missing_fields' => $e->missingFields,
            'new_fields' => $e->newFields,
        ]);

        $status = $this->runState->fail($node, 'schema_drift');

        if ($status === null) {
            return;
        }

        $this->failures->record($node, 'schema_drift');

        if ($status !== 'schema_drift') {
            return;
        }

        $this->notifications->notifySchemaDrift($node, $e->missingFields, $e->newFields);
        $this->failures->pushStatus($node, 'schema_drift');
    }

    /** @return bool false if the node's lock was busy or the claim was lost */
    public function processNode(Node $node, ExecutionContext $ctx): bool
    {
        $lock = Cache::store('redis')->lock("node:{$node->id}", $this->lockTtlSeconds);

        if (! $lock->get()) {
            // Probably an outdated run still holding the lock; give the claim back.
            $this->runState->release($node);

            return false;
        }

        try {
            $startedAt = hrtime(true);
            // The context outlives runs: take a token baseline and set this node's values.
            $tokensBefore = $ctx->tokensUsed();
            $ctx->setCurrentUserId((string) $node->user_id);
            // Only a confirmed schema is checked for drift; an inferred one just follows the result.
            $ctx->setExpectedColumnsSchema($node->columns_schema_confirmed ? $node->columns_schema ?? [] : []);
            $ctx->setWebhookPayload($node->webhook_payload);
            // Long operations call heartbeat() to keep the lock.
            $reporter = new LockHeartbeatReporter($lock, $this->lockTtlSeconds, $this->maxRunSeconds, $node->id);
            $ctx->setProgressReporter($reporter);

            // Known up front so drafts can be removed if the run fails.
            $runId = (string) Str::orderedUuid();

            try {
                try {
                    // Safe only while holding both the claim and the lock.
                    $ctx->rows()->discardAbandonedDrafts($node->id, $this->maxRunSeconds);

                    // A parent may change during the run; the commit compares against these versions.
                    $dependencyVersionsAtStart = $this->dependencyVersions($node);

                    $payload = $this->pipelineRunner->run($node->id, $node->dsl_config, $ctx, $runId);
                    $tokensSpent = $ctx->tokensUsed() - $tokensBefore;
                    $rowSet = RowSet::fromRun($payload->runId(), $node->id, $payload->count());
                    // The lock may have expired since the last heartbeat.
                    $reporter->ensureLockHeld();
                    $result = $this->resultStore->store($node, $rowSet, $dependencyVersionsAtStart);
                } catch (PDOException $e) {
                    // Database errors are retried like other failures instead of stopping the worker.
                    throw new PipelineException(count($node->dsl_config), 'commit', $e);
                }

                if ($result->version !== null) {
                    $this->wakeFor($result->staleChildIds);
                    $this->recordSuccess($node, $result, $rowSet->count(), $tokensSpent, $startedAt);
                }

                if ($result->status === 'fresh') {
                    // Sent even without a new version, so waiting clients can stop polling.
                    $this->failures->pushStatus($node, 'fresh');
                }

                return true;
            } catch (LockLostException $e) {
                $this->logAbandonedRun($node, $runId, $e);

                return false;
            } catch (PipelineException $e) {
                if (! $e->getPrevious() instanceof LockLostException) {
                    throw $e;
                }

                $this->logAbandonedRun($node, $runId, $e);

                return false;
            } finally {
                // Removes anything a failed run left behind.
                $this->discardDrafts($ctx, $runId, $node);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Only spares the children the wait for the next sweep, so a broker failure is just logged.
     *
     * @param  array<int, string>  $nodeIds
     */
    private function wakeFor(array $nodeIds): void
    {
        try {
            foreach ($nodeIds as $nodeId) {
                $this->eventPublisher->publishRecalcTask($nodeId);
            }
        } catch (Throwable $e) {
            Log::warning("Failed to wake workers for stale nodes: {$e->getMessage()}");
        }
    }

    /**
     * The result is committed, so a failure here must not fail the run.
     */
    private function recordSuccess(Node $node, StoreResult $result, int $rows, int $tokensSpent, int $startedAt): void
    {
        try {
            RunStat::create([
                'user_id' => $node->user_id,
                'node_id' => $node->id,
                'version_id' => $result->version->id,
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                'tokens_spent' => $tokensSpent,
                'rows_total' => $rows,
                'rows_changed' => $result->diff->count(),
                'status' => 'success',
            ]);

            $this->notifications->notifyDataChanged($node, $result->diff);
        } catch (PDOException $e) {
            Log::warning("Node {$node->id} was recomputed, but recording it failed: {$e->getMessage()}");
        }
    }

    /**
     * Must not replace an error already being thrown.
     */
    private function discardDrafts(ExecutionContext $ctx, string $runId, Node $node): void
    {
        try {
            $ctx->rows()->discardRun($runId, $node->id);
        } catch (PDOException $e) {
            Log::warning("Node {$node->id}: could not discard drafts of run {$runId}: {$e->getMessage()}");
        }
    }

    /**
     * A lost lock or claim leaves the node to its new owner; only this run's drafts are discarded.
     */
    private function logAbandonedRun(Node $node, string $runId, Throwable $e): void
    {
        Log::warning("Node {$node->id} run abandoned, lock lost: {$e->getMessage()}", [
            'node_id' => $node->id,
            'run_id' => $runId,
        ]);
    }

    /**
     * @return array<string, ?string> dependency node id => its current_version_id
     */
    private function dependencyVersions(Node $node): array
    {
        return $node->parents()->pluck('nodes.current_version_id', 'nodes.id')->all();
    }
}
