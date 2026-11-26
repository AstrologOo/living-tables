<?php

namespace Tests\Support\Fakes;

use App\Modules\Execution\Contracts\UsageLimiter;

class FakeUsageLimiter implements UsageLimiter
{
    public function __construct(private readonly ?string $reason = null) {}

    public function exceededReason(?string $userId, int $rowCount): ?string
    {
        return $this->reason;
    }
}
