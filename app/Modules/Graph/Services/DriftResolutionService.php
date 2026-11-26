<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use InvalidArgumentException;

/**
 * The two manual ways out of `schema_drift`: the field is back, or it is gone for good. Renaming a column isn't
 * supported, because col_id and the source field name are the same thing.
 */
class DriftResolutionService
{
    /**
     * A resolved node is queued again, with no failures counted against it and no retry delay.
     */
    private const REQUEUE = ['status' => 'stale', 'consecutive_failures' => 0, 'retry_at' => null];

    /**
     * Queues the node again with its schema unchanged: the drift was temporary.
     *
     * @throws InvalidArgumentException if $node isn't currently schema_drift
     */
    public function confirmFieldsReturned(Node $node): void
    {
        $this->assertDrifted($node);

        $node->update(self::REQUEUE);
    }

    /**
     * Removes the column from the schema and queues the node. Anything downstream that still uses it will fail on
     * its next run.
     *
     * @throws InvalidArgumentException if $node isn't currently schema_drift, or $colId isn't declared
     */
    public function removeColumn(Node $node, string $colId): void
    {
        $this->assertDrifted($node);

        $schema = $node->columns_schema ?? [];

        if (! array_key_exists($colId, $schema)) {
            throw new InvalidArgumentException("Node {$node->id} has no column \"{$colId}\" to remove.");
        }

        unset($schema[$colId]);

        $node->update(['columns_schema' => $schema, ...self::REQUEUE]);
    }

    private function assertDrifted(Node $node): void
    {
        if ($node->status !== 'schema_drift') {
            throw new InvalidArgumentException(
                "Node {$node->id} is not in schema_drift (status: \"{$node->status}\"), nothing to resolve."
            );
        }
    }
}
