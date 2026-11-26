<?php

use App\Modules\Graph\Support\Aggregates\AggregateFunction;
use App\Modules\Graph\Support\Aggregates\SqliteAggregateDialect;
use App\Modules\Graph\Support\Aggregates\UnsupportedAggregateException;
use App\Modules\Graph\Support\NumericValue;

it('supports count/count_distinct/sum/avg/min/max/median', function (string $function) {
    $dialect = new SqliteAggregateDialect;

    expect(fn () => $dialect->aggregateExpr($function, 'price', 'number'))->not->toThrow(Throwable::class);
})->with([
    AggregateFunction::COUNT, AggregateFunction::COUNT_DISTINCT, AggregateFunction::SUM,
    AggregateFunction::AVG, AggregateFunction::MIN, AggregateFunction::MAX, AggregateFunction::MEDIAN,
]);

it('throws UnsupportedAggregateException for stddev and variance, rather than faking them', function (string $function) {
    $dialect = new SqliteAggregateDialect;

    expect(fn () => $dialect->aggregateExpr($function, 'price', 'number'))
        ->toThrow(UnsupportedAggregateException::class, 'not supported on the "sqlite" driver');
})->with([AggregateFunction::STDDEV, AggregateFunction::VARIANCE]);

it('uses its own lowercase median() function', function () {
    [$sql] = (new SqliteAggregateDialect)->aggregateExpr(AggregateFunction::MEDIAN, 'price', 'number');

    expect($sql)->toBe('median('.NumericValue::sql('price', 'sqlite')[0].')');
});

it('reads a number-typed column as a checked number, as Postgres does', function () {
    [$sql, $bindings] = (new SqliteAggregateDialect)->aggregateExpr(AggregateFunction::SUM, 'price', 'number');
    [$number, $numberBindings] = NumericValue::sql('price', 'sqlite');

    expect($sql)->toBe("SUM({$number})")
        ->and($bindings)->toBe($numberBindings);
});
