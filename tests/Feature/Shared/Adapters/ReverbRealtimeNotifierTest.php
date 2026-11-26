<?php

use App\Modules\Shared\Adapters\Broadcast\ReverbRealtimeNotifier;
use Illuminate\Support\Facades\Log;

function useReverb(string $key, string $host): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => $key,
        'broadcasting.connections.reverb.secret' => $key,
        'broadcasting.connections.reverb.app_id' => $key,
        'broadcasting.connections.reverb.options.host' => $host,
        'broadcasting.connections.reverb.options.port' => 1,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);
}

it('logs a warning instead of throwing when the server is unreachable or the keys are empty', function (string $key, string $host) {
    useReverb($key, $host);
    Log::spy();

    (new ReverbRealtimeNotifier)->notify(1, 'node.status_changed', ['status' => 'fresh']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'node.status_changed'))
        ->once();
})->with([
    'unreachable' => ['key', '127.0.0.1'],
    'empty keys, unresolvable host' => ['', 'reverb.invalid'],
]);
