<?php

namespace App\Modules\Graph\Support\Aggregates;

/**
 * sqlite dialect, used by tests; stddev and variance are unsupported.
 */
class SqliteAggregateDialect implements AggregateDialect
{
    use BuildsColumnExpr;

    public function aggregateExpr(string $function, string $colId, ?string $columnType): array
    {
        if ($function === AggregateFunction::COUNT) {
            return ['COUNT(*)', []];
        }

        if (in_array($function, [AggregateFunction::STDDEV, AggregateFunction::VARIANCE], true)) {
            throw new UnsupportedAggregateException($function, 'sqlite');
        }

        [$col, $bindings] = $this->columnExpr($function, $colId, $columnType, 'sqlite');

        $sql = match ($function) {
            AggregateFunction::COUNT_DISTINCT => "COUNT(DISTINCT {$col})",
            AggregateFunction::SUM => "SUM({$col})",
            AggregateFunction::AVG => "AVG({$col})",
            AggregateFunction::MIN => "MIN({$col})",
            AggregateFunction::MAX => "MAX({$col})",
            AggregateFunction::MEDIAN => SqliteMedian::FUNCTION."({$col})",
            default => throw new UnsupportedAggregateException($function, 'sqlite'),
        };

        return [$sql, $bindings];
    }
}
