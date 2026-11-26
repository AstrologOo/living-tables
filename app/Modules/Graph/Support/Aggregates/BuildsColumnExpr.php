<?php

namespace App\Modules\Graph\Support\Aggregates;

use App\Modules\Graph\Support\NumericValue;

/**
 * SQL expression for a column's value, shared by the dialects.
 */
trait BuildsColumnExpr
{
    /**
     * @param  string  $driver  'pgsql' or 'sqlite'
     * @return array{0: string, 1: array<int, string>} the expression and its bindings
     */
    private function columnExpr(string $function, string $colId, ?string $columnType, string $driver): array
    {
        $needsNumeric = in_array($function, AggregateFunction::NEEDS_NUMERIC_CAST, true) && $columnType === 'number';

        return $needsNumeric ? NumericValue::sql($colId, $driver) : ['node_rows.data->>?', [$colId]];
    }
}
