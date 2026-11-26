<?php

namespace App\Modules\Intake;

use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\Operations\LimitOperation;
use App\Modules\Execution\Operations\SortOperation;
use App\Modules\Execution\Operations\UniqueOperation;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Execution\RowBatch;
use App\Modules\Graph\Exceptions\UndeclaredReferenceException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Support\DslTailSplitter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a candidate config on the node's current parent data without saving a result or changing statuses. Only
 * records run stats, as tokens are spent.
 */
class PreviewService
{
    /**
     * @var array<int, array{op: string, rows_before: int, rows_after: int}>
     */
    private array $lastStepStats = [];

    public function __construct(
        private readonly ExecutionContext $ctx,
        private readonly ?PipelineRunner $pipelineRunner = null,
        private readonly GraphService $graph = new GraphService,
    ) {}

    /**
     * Row counts per step from the last preview.
     *
     * @return array<int, array{op: string, rows_before: int, rows_after: int}>
     */
    public function lastStepStats(): array
    {
        return $this->lastStepStats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $newConfig
     */
    public function preview(Node $node, array $newConfig): RowBatch
    {
        try {
            $this->graph->assertReadsOnlyDeclaredParents($node, $newConfig);
        } catch (UndeclaredReferenceException $e) {
            throw new DslException($e->getMessage(), previous: $e);
        }

        // The trailing steps aren't run by the pipeline, so apply them here to show what the default view would.
        $split = DslTailSplitter::split($newConfig);

        $tokensBefore = $this->ctx->tokensUsed();
        $startedAt = hrtime(true);
        $status = 'preview';
        $rowsTotal = 0;

        // Known up front so the drafts can be removed if the run fails.
        $runId = (string) Str::orderedUuid();

        try {
            $payload = ($this->pipelineRunner ?? new PipelineRunner)->run($node->id, $split['executable'], $this->ctx, $runId);
            $rowsTotal = $payload->count();
            $this->lastStepStats = $payload->stepStats();

            $rows = $this->ctx->rows()->draftRows($payload->runId(), $node->id);
            $batch = $this->applyTailCriteria(RowBatch::fromRows($rows, $payload->columnsSchema()), $split);
            $rowsTotal = $batch->count();

            return $batch;
        } catch (Throwable $e) {
            $status = 'preview_failed';

            throw $e;
        } finally {
            // A preview keeps no rows.
            $this->ctx->rows()->discardRun($runId, $node->id);

            RunStat::create([
                'user_id' => $node->user_id,
                'node_id' => $node->id,
                'version_id' => null,
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                'tokens_spent' => $this->ctx->tokensUsed() - $tokensBefore,
                'rows_total' => $rowsTotal,
                'rows_changed' => 0,
                'status' => $status,
            ]);
        }
    }

    /**
     * @param  array{sort_field: ?string, sort_order: ?string, unique_field: ?string, limit_count: ?int}  $split
     */
    private function applyTailCriteria(RowBatch $batch, array $split): RowBatch
    {
        if ($split['unique_field'] !== null) {
            $batch = (new UniqueOperation)->apply($batch, ['field' => $split['unique_field']], $this->ctx);
        }

        if ($split['sort_field'] !== null) {
            $batch = (new SortOperation)->apply($batch, ['field' => $split['sort_field'], 'order' => $split['sort_order']], $this->ctx);
        }

        if ($split['limit_count'] !== null) {
            $batch = (new LimitOperation)->apply($batch, ['count' => $split['limit_count']], $this->ctx);
        }

        return $batch;
    }
}
