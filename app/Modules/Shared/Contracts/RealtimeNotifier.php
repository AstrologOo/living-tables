<?php

namespace App\Modules\Shared\Contracts;

/**
 * Pushes small events to a user's connected clients.
 */
interface RealtimeNotifier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function notify(int $userId, string $event, array $payload): void;
}
