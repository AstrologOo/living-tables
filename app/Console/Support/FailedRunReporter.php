<?php

namespace App\Console\Support;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\NotificationService;
use App\Modules\Shared\Contracts\RealtimeNotifier;

/**
 * Records statistics for a run without a result and tells the owner if the node was given up on.
 */
class FailedRunReporter
{
    public function __construct(private readonly NotificationService $notifications = new NotificationService) {}

    /**
     * Handles a node just marked `failed`; $publicMessage goes into the owner's notification.
     */
    public function failed(Node $node, string $publicMessage): void
    {
        $this->record($node, 'failed');
        $this->notifications->notifyFailed($node, $publicMessage);
        $this->pushStatus($node, 'failed');
    }

    public function record(Node $node, string $status): void
    {
        RunStat::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'version_id' => null,
            'duration_ms' => 0,
            'tokens_spent' => 0,
            'rows_total' => 0,
            'rows_changed' => 0,
            'status' => $status,
        ]);
    }

    public function pushStatus(Node $node, string $status): void
    {
        app(RealtimeNotifier::class)->notify((int) $node->user_id, 'node.status_changed', [
            'node_id' => $node->id,
            'status' => $status,
        ]);
    }
}
