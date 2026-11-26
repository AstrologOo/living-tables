<?php

use App\Modules\Graph\Support\Aggregates\AggregateDialectResolver;
use App\Modules\Graph\Support\Aggregates\PostgresAggregateDialect;
use App\Modules\Graph\Support\Aggregates\SqliteAggregateDialect;
use Illuminate\Support\Facades\DB;

it('resolves to the dialect matching whichever connection this test run is actually using', function () {
    $expected = match (DB::connection()->getDriverName()) {
        'pgsql' => PostgresAggregateDialect::class,
        'sqlite' => SqliteAggregateDialect::class,
    };

    expect((new AggregateDialectResolver)->resolve())->toBeInstanceOf($expected);
});
