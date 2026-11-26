<?php

namespace App\Console\Support;

use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\Exceptions\RunBudgetExceededException;
use App\Modules\Graph\Exceptions\LockLostException;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the node's Redis lock alive during a long run, enforces the run's time budget and publishes live
 * progress to Redis.
 */
class LockHeartbeatReporter implements ProgressReporter
{
    private readonly float $startedAt;

    public function __construct(
        private readonly Lock $lock,
        private readonly int $lockTtlSeconds,
        private readonly int $maxRunSeconds,
        private readonly ?string $nodeId = null,
        private readonly int $liveTtlSeconds = 300,
    ) {
        $this->startedAt = microtime(true);
    }

    public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
    {
        $elapsed = microtime(true) - $this->startedAt;

        if ($elapsed > $this->maxRunSeconds) {
            throw new RunBudgetExceededException(
                "Node run exceeded its {$this->maxRunSeconds}s wall-clock budget."
            );
        }

        $this->ensureLockHeld();

        if ($rowsSoFar !== null && $this->nodeId !== null) {
            Cache::store('redis')->put("node:{$this->nodeId}:live", [
                'processed' => $processed,
                'rows' => $rowsSoFar,
                'updated_at' => now()->toIso8601String(),
            ], $this->liveTtlSeconds);
        }
    }

    /**
     * Refreshes the lock, failing if it is no longer ours; the time budget isn't checked.
     *
     * @throws LockLostException
     */
    public function ensureLockHeld(): void
    {
        if (! $this->lock->refresh($this->lockTtlSeconds)) {
            throw new LockLostException(
                'Lost the lock'.($this->nodeId !== null ? " on node {$this->nodeId}" : '').' during the run.'
            );
        }
    }
}
