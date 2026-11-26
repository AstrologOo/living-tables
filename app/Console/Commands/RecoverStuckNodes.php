<?php

namespace App\Console\Commands;

use App\Console\Support\FailedRunReporter;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\NodeRunStateService;
use App\Support\PublicErrorMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Takes back `processing` nodes whose Redis lock is free, meaning no worker is running them, and counts each
 * as a failed run. The age check covers the moment between claim and lock.
 */
class RecoverStuckNodes extends Command
{
    protected $signature = 'graph:recover-stuck-nodes';

    protected $description = 'Count nodes stuck in processing with no worker holding their lock as failed runs: retry later, or give up';

    public function handle(NodeRunStateService $runState, FailedRunReporter $failures): int
    {
        $stuck = Node::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subSeconds(60))
            ->get();

        $recovered = 0;
        $givenUp = 0;

        foreach ($stuck as $node) {
            $lock = Cache::store('redis')->lock("node:{$node->id}", 5);

            if (! $lock->get()) {
                continue; // A worker still holds the lock.
            }

            try {
                // Only if the claim hasn't changed; the worker's sweep picks the node up after its delay.
                $status = $runState->retryLater($node);

                if ($status === 'stale') {
                    $recovered++;
                    Log::warning("Node {$node->id} run was interrupted, it will be retried.");
                } elseif ($status === 'failed') {
                    $givenUp++;
                    Log::error("Node {$node->id} run was interrupted too many times, giving up.");
                    $failures->failed($node, PublicErrorMessage::RUN_INTERRUPTED);
                }
            } finally {
                $lock->release();
            }
        }

        $this->info("Recovered {$recovered} orphaned processing node(s), gave up on {$givenUp}.");

        return self::SUCCESS;
    }
}
