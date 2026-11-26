<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Contracts\RealtimeNotifier;

class FakeRealtimeNotifier implements RealtimeNotifier
{
    /** @var array<int, array{userId: int, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function notify(int $userId, string $event, array $payload): void
    {
        $this->sent[] = ['userId' => $userId, 'event' => $event, 'payload' => $payload];
    }
}
