<?php

namespace App\Modules\Shared\Adapters\Broadcast;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A named event on a user's private channel. Broadcast immediately, as the client is waiting for it.
 */
class UserEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("App.Models.User.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
