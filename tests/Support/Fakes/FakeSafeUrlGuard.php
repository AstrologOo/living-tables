<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Support\SafeUrlGuard;

/**
 * Allows any host, so feature tests can fake "*.test" hosts that never resolve. The real guard has its own tests.
 */
class FakeSafeUrlGuard extends SafeUrlGuard
{
    public function assertAllowed(string $url): string
    {
        return '93.184.216.34';
    }
}
