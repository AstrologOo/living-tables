<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Execution\Contracts\NodeNotReadableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rule behind every read of another node: it is a declared parent of the reader and has the same owner.
 */
final class ReadableParent
{
    /**
     * @throws NodeNotReadableException
     */
    public static function assert(string $parentId, string $readerId): void
    {
        // Postgres rejects a malformed id with an error that spoils the transaction, so it never gets there.
        $readable = Str::isUuid($parentId) && Str::isUuid($readerId) && DB::table('edges')
            ->join('nodes as parent', 'parent.id', '=', 'edges.depends_on_id')
            ->join('nodes as reader', 'reader.id', '=', 'edges.node_id')
            ->where('edges.node_id', $readerId)
            ->where('edges.depends_on_id', $parentId)
            ->whereColumn('parent.user_id', 'reader.user_id')
            ->exists();

        if (! $readable) {
            throw new NodeNotReadableException($parentId);
        }
    }
}
