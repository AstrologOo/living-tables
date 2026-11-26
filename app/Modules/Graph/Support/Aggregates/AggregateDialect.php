<?php

namespace App\Modules\Graph\Support\Aggregates;

/**
 * Driver-specific SQL for aggregate functions; a driver throws for functions it lacks rather than emulating
 * them.
 */
interface AggregateDialect
{
    /**
     * @param  string  $function  one of AggregateFunction::* constants
     * @param  string  $colId  the column being aggregated (ignored for 'count')
     * @param  ?string  $columnType  the declared column type; 'number' requires a NUMERIC cast
     * @return array{0: string, 1: array<int, mixed>} the SQL expression with `?` placeholders, and its bindings
     *
     * @throws UnsupportedAggregateException if $function isn't available on this driver
     */
    public function aggregateExpr(string $function, string $colId, ?string $columnType): array;
}
