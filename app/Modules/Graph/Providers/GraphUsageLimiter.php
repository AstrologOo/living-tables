<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Execution\Contracts\UsageLimiter;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Models\UserLimit;
use Illuminate\Support\Facades\Date;

class GraphUsageLimiter implements UsageLimiter
{
    public function exceededReason(?string $userId, int $rowCount): ?string
    {
        if ($userId === null) {
            return null;
        }

        $limit = UserLimit::query()->find($userId);

        if ($limit === null) {
            return null;
        }

        if ($limit->rows_per_node !== null && $rowCount > $limit->rows_per_node) {
            return "Row limit exceeded: this llm step would process {$rowCount} rows, "
                ."the configured limit is {$limit->rows_per_node} per node.";
        }

        if ($limit->tokens_per_month !== null) {
            $usedThisMonth = (int) RunStat::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', Date::now()->startOfMonth())
                ->sum('tokens_spent');

            if ($usedThisMonth >= $limit->tokens_per_month) {
                return "Monthly token limit exceeded: {$usedThisMonth} tokens already spent this month, "
                    ."the configured limit is {$limit->tokens_per_month}.";
            }
        }

        return null;
    }
}
