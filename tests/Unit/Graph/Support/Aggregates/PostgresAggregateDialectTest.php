<?php

use App\Modules\Graph\Support\Aggregates\AggregateFunction;
use App\Modules\Graph\Support\Aggregates\PostgresAggregateDialect;
use App\Modules\Graph\Support\NumericValue;

it('supports every AggregateFunction', function (string $function) {
    $dialect = new PostgresAggregateDialect;

    expect(fn () => $dialect->aggregateExpr($function, 'price', 'number'))->not->toThrow(Throwable::class);
})->with(AggregateFunction::ALL);

it('count ignores the column entirely', function () {
    [$sql, $bindings] = (new PostgresAggregateDialect)->aggregateExpr(AggregateFunction::COUNT, 'price', 'number');

    expect($sql)->toBe('COUNT(*)')->and($bindings)->toBe([]);
});

it('reads a number-typed column as a checked number for sum/avg/min/max/median/stddev/variance', function (string $function) {
    [$sql, $bindings] = (new PostgresAggregateDialect)->aggregateExpr($function, 'price', 'number');
    [$number, $numberBindings] = NumericValue::sql('price', 'pgsql');

    expect($sql)->toContain($number)
        ->and($bindings)->toBe($numberBindings);
})->with([
    AggregateFunction::SUM, AggregateFunction::AVG, AggregateFunction::MIN, AggregateFunction::MAX,
    AggregateFunction::MEDIAN, AggregateFunction::STDDEV, AggregateFunction::VARIANCE,
]);

it('does not cast a string-typed column', function () {
    [$sql] = (new PostgresAggregateDialect)->aggregateExpr(AggregateFunction::MIN, 'sku', 'string');

    expect($sql)->toBe('MIN(node_rows.data->>?)');
});

it('uses PERCENTILE_CONT WITHIN GROUP for median', function () {
    [$sql] = (new PostgresAggregateDialect)->aggregateExpr(AggregateFunction::MEDIAN, 'price', 'number');

    expect($sql)->toBe('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY '.NumericValue::sql('price', 'pgsql')[0].')');
});

it('count_distinct never casts, even for a numeric column', function () {
    [$sql] = (new PostgresAggregateDialect)->aggregateExpr(AggregateFunction::COUNT_DISTINCT, 'price', 'number');

    expect($sql)->toBe('COUNT(DISTINCT node_rows.data->>?)');
});
