<?php

namespace App\Modules\Graph\Support\Aggregates;

/**
 * Postgres dialect; supports every aggregate function.
 */
class PostgresAggregateDialect implements AggregateDialect
{
    use BuildsColumnExpr;

    public function aggregateExpr(string $function, string $colId, ?string $columnType): array
    {
        if ($function === AggregateFunction::COUNT) {
            return ['COUNT(*)', []];
        }

        [$col, $bindings] = $this->columnExpr($function, $colId, $columnType, 'pgsql');

        $sql = match ($function) {
            AggregateFunction::COUNT_DISTINCT => "COUNT(DISTINCT {$col})",
            AggregateFunction::SUM => "SUM({$col})",
            AggregateFunction::AVG => "AVG({$col})",
            AggregateFunction::MIN => "MIN({$col})",
            AggregateFunction::MAX => "MAX({$col})",
            AggregateFunction::MEDIAN => "PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$col})",
            AggregateFunction::STDDEV => "STDDEV_POP({$col})",
            AggregateFunction::VARIANCE => "VAR_POP({$col})",
            default => throw new UnsupportedAggregateException($function, 'pgsql'),
        };

        return [$sql, $bindings];
    }
}
