<?php

use App\Console\Support\LockHeartbeatReporter;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;

function recordingLockForLiveTest(): Lock
{
    return new class('lock', 0) extends Lock
    {
        public function acquire(): bool
        {
            return true;
        }

        public function release(): bool
        {
            return true;
        }

        public function refresh($seconds = null): bool
        {
            return true;
        }

        protected function getCurrentOwner(): string
        {
            return 'test';
        }

        public function forceRelease(): void {}
    };
}

it('writes rowsSoFar to the live Redis channel when both nodeId and rows are given', function () {
    $reporter = new LockHeartbeatReporter(recordingLockForLiveTest(), 300, 1800, nodeId: 'n1');

    $reporter->heartbeat(5, [['row_key' => 'r1', 'data' => ['x' => 1]]]);

    $live = Cache::store('redis')->get('node:n1:live');

    expect($live['processed'])->toBe(5)
        ->and($live['rows'])->toBe([['row_key' => 'r1', 'data' => ['x' => 1]]])
        ->and($live['updated_at'])->not->toBeNull();
});

it('does not write to the live channel when rowsSoFar is not given', function () {
    $reporter = new LockHeartbeatReporter(recordingLockForLiveTest(), 300, 1800, nodeId: 'n2');

    $reporter->heartbeat(5);

    expect(Cache::store('redis')->get('node:n2:live'))->toBeNull();
});

it('does not write to the live channel when no nodeId was given', function () {
    $reporter = new LockHeartbeatReporter(recordingLockForLiveTest(), 300, 1800);

    $reporter->heartbeat(5, [['row_key' => 'r1', 'data' => []]]);

    expect(Cache::store('redis')->get('node::live'))->toBeNull();
});

afterEach(function () {
    Cache::store('redis')->forget('node:n1:live');
    Cache::store('redis')->forget('node:n2:live');
});
