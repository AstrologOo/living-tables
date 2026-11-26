<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Ends a run that produced no result. Writes only while the caller's claim_token still matches, and clears it.
 */
class NodeRunStateService
{
    /**
     * Unsuccessful runs in a row after which the node is given up on: a
     * retryable failure becomes `failed`, and any failure is recorded even
     * though newer data arrived meanwhile.
     */
    public const MAX_FAILURES_BEFORE_GIVING_UP = 3;

    /**
     * How long a node waits after its 1st, 2nd, ... failure in a row. With
     * the limit above at 3, the last delay is only reached if it's raised.
     */
    public const RETRY_DELAYS_SECONDS = [30, 60, 120];

    /**
     * Records a failure worth retrying: the node goes back to `stale`, not
     * claimable until its delay passes — or to `failed` once it has failed
     * MAX_FAILURES_BEFORE_GIVING_UP times in a row.
     *
     * @return 'stale'|'failed'|null the status written, or null if the claim was no longer ours
     */
    public function retryLater(Node $node): ?string
    {
        return $this->countFailure($node, function (Node $current, int $failures) {
            if ($failures >= self::MAX_FAILURES_BEFORE_GIVING_UP) {
                return ['status' => 'failed', 'retry_at' => null];
            }

            $delay = self::RETRY_DELAYS_SECONDS[min($failures, count(self::RETRY_DELAYS_SECONDS)) - 1];

            return ['status' => 'stale', 'retry_at' => now()->addSeconds($delay)];
        });
    }

    /**
     * Gives a claim back without having run anything.
     *
     * @return bool false if the claim was no longer ours
     */
    public function release(Node $node): bool
    {
        return $this->leaveProcessing($node, ['status' => 'stale']);
    }

    /**
     * Records a permanent failure. If newer data arrived meanwhile the node goes back to `stale` instead, up to
     * MAX_FAILURES_BEFORE_GIVING_UP times.
     *
     * @param  'failed'|'schema_drift'  $status
     * @return ?string the status actually written, or null if the claim was no longer ours
     */
    public function fail(Node $node, string $status): ?string
    {
        return $this->countFailure($node, function (Node $current, int $failures) use ($node, $status) {
            $hasNewerData = $node->data_revision !== null && $current->data_revision !== $node->data_revision;

            return [
                'status' => $hasNewerData && $failures < self::MAX_FAILURES_BEFORE_GIVING_UP ? 'stale' : $status,
                'retry_at' => null,
            ];
        });
    }

    /**
     * @param  Closure(Node $current, int $failures): array{status: string, retry_at: mixed}  $outcome
     * @return ?string the status written, or null if the claim was no longer ours
     */
    private function countFailure(Node $node, Closure $outcome): ?string
    {
        return DB::transaction(function () use ($node, $outcome) {
            $current = Node::query()->whereKey($node->id)->lockForUpdate()->first();

            if ($current === null || $current->claim_token !== $node->claim_token) {
                return null;
            }

            $failures = $current->consecutive_failures + 1;
            $changes = [...$outcome($current, $failures), 'consecutive_failures' => $failures];

            return $this->leaveProcessing($node, $changes) ? $changes['status'] : null;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function leaveProcessing(Node $node, array $changes): bool
    {
        $written = Node::query()
            ->whereKey($node->id)
            // A null token matches only a null token.
            ->where('claim_token', $node->claim_token)
            ->update([...$changes, 'claim_token' => null]);

        if ($written === 0) {
            return false;
        }

        $node->forceFill(['status' => $changes['status'], 'claim_token' => null])
            ->syncOriginalAttributes(['status', 'claim_token']);

        return true;
    }
}
