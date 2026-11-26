<?php

namespace App\Modules\Shared\Adapters\Broadcast;

use App\Modules\Shared\Contracts\RealtimeNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RealtimeNotifier over Laravel broadcasting (Reverb).
 */
class ReverbRealtimeNotifier implements RealtimeNotifier
{
    /**
     * Best-effort: the change is already committed, so a broadcast failure is only logged.
     */
    public function notify(int $userId, string $event, array $payload): void
    {
        try {
            broadcast(new UserEvent($userId, $event, $payload));
        } catch (Throwable $e) {
            Log::warning("Realtime push \"{$event}\" to user {$userId} failed: {$e->getMessage()}");
        }
    }
}
