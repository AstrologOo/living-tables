<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchedulerService
{
    /**
     * Claims one `stale` node that has all parents `fresh` and no pending retry delay: sets it `processing` with a
     * new claim_token. SKIP LOCKED keeps two workers off the same node.
     *
     * @param  array<int, string>  $excludeIds  nodes to skip, so one that keeps failing doesn't starve the rest
     */
    public function claimReadyNode(array $excludeIds = []): ?Node
    {
        $lockClause = DB::connection()->getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : '';
        $excludeClause = $excludeIds === [] ? '' : 'AND n.id NOT IN ('.implode(',', array_fill(0, count($excludeIds), '?')).')';

        $rows = DB::select(<<<SQL
            WITH candidate AS (
                SELECT n.id FROM nodes n
                WHERE n.status = 'stale'
                  AND (n.retry_at IS NULL OR n.retry_at <= ?)
                  {$excludeClause}
                  AND NOT EXISTS (
                      SELECT 1 FROM edges e
                      INNER JOIN nodes p ON p.id = e.depends_on_id
                      WHERE e.node_id = n.id AND p.status <> 'fresh'
                  )
                ORDER BY n.created_at
                LIMIT 1
                {$lockClause}
            )
            UPDATE nodes
            SET status = 'processing', claim_token = ?, updated_at = ?
            WHERE id = (SELECT id FROM candidate)
            RETURNING *
            SQL, [now(), ...$excludeIds, (string) Str::uuid(), now()]);

        if ($rows === []) {
            return null;
        }

        return (new Node)->newFromBuilder((array) $rows[0]);
    }
}
