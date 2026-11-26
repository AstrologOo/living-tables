<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use Illuminate\Support\Facades\DB;

class InvalidationService
{
    /**
     * Marks the node's fresh and failed direct children `stale` and returns how many.
     */
    public function markChildrenStale(Node $node): int
    {
        return count($this->markChildrenStaleIds($node));
    }

    /**
     * Same, returning their ids. Runs inside the commit of a new version; failure counts are kept, so a child that
     * was given up on gets one more run.
     *
     * @return array<int, string>
     */
    public function markChildrenStaleIds(Node $node): array
    {
        $rows = DB::select(<<<'SQL'
            UPDATE nodes
            SET status = 'stale'
            WHERE status IN ('fresh', 'failed')
              AND id IN (SELECT node_id FROM edges WHERE depends_on_id = ?)
            RETURNING id
            SQL, [$node->id]);

        return array_column($rows, 'id');
    }
}
