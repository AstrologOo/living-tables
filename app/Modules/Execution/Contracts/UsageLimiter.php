<?php

namespace App\Modules\Execution\Contracts;

/**
 * Port to per-user usage limits.
 */
interface UsageLimiter
{
    /**
     * @param  int  $rowCount  rows about to go into this llm step
     * @return string|null why a limit is exceeded, or null if none is
     */
    public function exceededReason(?string $userId, int $rowCount): ?string;
}
