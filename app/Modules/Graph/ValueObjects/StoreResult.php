<?php

namespace App\Modules\Graph\ValueObjects;

use App\Modules\Graph\Models\NodeVersion;

/**
 * Outcome of ResultStore::store(): the new version (null if unchanged), the row diff, the status written and
 * the children that became stale.
 */
final class StoreResult
{
    /**
     * @param  array<int, string>  $staleChildIds
     */
    public function __construct(
        public readonly ?NodeVersion $version,
        public readonly DiffResult $diff,
        public readonly string $status,
        public readonly array $staleChildIds = [],
    ) {}
}
