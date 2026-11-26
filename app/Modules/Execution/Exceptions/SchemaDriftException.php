<?php

namespace App\Modules\Execution\Exceptions;

use RuntimeException;

/**
 * A used source field vanished or changed type; the node goes to `schema_drift` and waits for a human.
 */
class SchemaDriftException extends RuntimeException
{
    /**
     * @param  array<string, string>  $missingFields  col_id => expected external name
     * @param  array<int, string>  $newFields  unrecognized field names seen in the fetched data (informational only)
     */
    public function __construct(
        public readonly array $missingFields,
        public readonly array $newFields = [],
    ) {
        $described = implode(', ', array_map(
            fn (string $colId, string $name) => "\"{$name}\" (col_id={$colId})",
            array_keys($missingFields),
            $missingFields,
        ));

        parent::__construct("Schema drift: field(s) missing or type-mismatched: {$described}.");
    }
}
