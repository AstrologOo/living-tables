<?php

namespace App\Modules\Graph\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wakes the worker after a node became `stale`. Failures are only logged: the change is already saved and the
 * worker also polls.
 */
class WorkerWaker
{
    public function __construct(private readonly RabbitMqTopology $topology) {}

    public function wake(string $nodeId): void
    {
        try {
            $channel = $this->topology->connect();
            $this->topology->declare($channel);
            (new NodeEventPublisher($channel))->publishRecalcTask($nodeId);
            $channel->getConnection()->close();
        } catch (Throwable $e) {
            Log::warning("Failed to wake the worker for node {$nodeId}: {$e->getMessage()}");
        }
    }
}
