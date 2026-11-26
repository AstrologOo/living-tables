<?php

namespace App\Modules\Execution\Contracts;

/**
 * Lets a long-running step check in, so the caller can keep its lock alive and enforce a time budget.
 */
interface ProgressReporter
{
    /**
     * @param  int|null  $processed  rows, pages or batches done so far in this step
     * @param  array<int, mixed>|null  $rowsSoFar  rows gathered so far, for live progress only
     *
     * Throws once the run's time budget has elapsed.
     */
    public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void;
}
