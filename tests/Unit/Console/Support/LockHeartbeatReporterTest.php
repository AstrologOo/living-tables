<?php

use App\Console\Support\LockHeartbeatReporter;
use App\Modules\Execution\Exceptions\RunBudgetExceededException;
use App\Modules\Graph\Exceptions\LockLostException;
use Illuminate\Cache\Lock;

function recordingLock(bool $stillOwned = true): Lock
{
    $lock = new class('lock', 0) extends Lock
    {
        public array $refreshedWith = [];

        public bool $stillOwned = true;

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
            $this->refreshedWith[] = $seconds;

            return $this->stillOwned;
        }

        protected function getCurrentOwner(): string
        {
            return 'test';
        }

        public function forceRelease(): void {}
    };
    $lock->stillOwned = $stillOwned;

    return $lock;
}

it('refreshes the lock TTL on heartbeat while inside budget', function () {
    $lock = recordingLock();

    (new LockHeartbeatReporter($lock, lockTtlSeconds: 300, maxRunSeconds: 1800))->heartbeat();

    expect($lock->refreshedWith)->toBe([300]);
});

it('throws RunBudgetExceededException instead of refreshing once the wall-clock budget is exceeded', function () {
    $lock = recordingLock();
    $reporter = new LockHeartbeatReporter($lock, lockTtlSeconds: 300, maxRunSeconds: -1);

    expect(fn () => $reporter->heartbeat())->toThrow(RunBudgetExceededException::class);
    expect($lock->refreshedWith)->toBe([]);
});

it('throws LockLostException when the lock can no longer be refreshed', function () {
    $reporter = new LockHeartbeatReporter(recordingLock(stillOwned: false), lockTtlSeconds: 300, maxRunSeconds: 1800);

    expect(fn () => $reporter->heartbeat())->toThrow(LockLostException::class);
});
