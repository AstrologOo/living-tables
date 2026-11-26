<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Support\SafeUrlGuard;

/**
 * The real guard with only DNS replaced, so range checks stay in force and
 * hostname tests run offline. Hosts not listed don't resolve.
 */
class StubDnsSafeUrlGuard extends SafeUrlGuard
{
    /** @param array<string, array<int, string>> $records host => addresses */
    public function __construct(private readonly array $records) {}

    protected function resolve(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
