<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeNotification;
use App\Modules\Graph\ValueObjects\DiffResult;
use App\Modules\Shared\Contracts\RealtimeNotifier;

/**
 * Stores in-app notifications and pushes each to connected clients.
 */
class NotificationService
{
    public function notifyDataChanged(Node $node, DiffResult $diff): void
    {
        if ($diff->count() === 0) {
            return;
        }

        $notification = NodeNotification::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'type' => 'data_changed',
            'payload' => [
                'added' => count($diff->added),
                'changed' => count($diff->changed),
                'removed' => count($diff->removed),
            ],
        ]);

        $this->push($notification);
    }

    /**
     * @param  array<string, string>  $missingFields  col_id => expected external name
     * @param  array<int, string>  $newFields  unrecognized field names seen in the fetched data
     */
    public function notifySchemaDrift(Node $node, array $missingFields, array $newFields = []): void
    {
        $notification = NodeNotification::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'type' => 'schema_drift',
            'payload' => [
                'missing_fields' => $missingFields,
                'new_fields' => $newFields,
            ],
        ]);

        $this->push($notification);
    }

    /**
     * @param  string  $message  shown to the user as is — never a raw
     *                           exception message (see PublicErrorMessage)
     */
    public function notifyFailed(Node $node, string $message): void
    {
        $notification = NodeNotification::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'type' => 'failed',
            'payload' => [
                'message' => $message,
            ],
        ]);

        $this->push($notification);
    }

    private function push(NodeNotification $notification): void
    {
        app(RealtimeNotifier::class)->notify($notification->user_id, 'notification.created', [
            'id' => $notification->id,
            'node_id' => $notification->node_id,
            'type' => $notification->type,
            'payload' => $notification->payload,
            'created_at' => $notification->created_at?->toISOString(),
        ]);
    }
}
