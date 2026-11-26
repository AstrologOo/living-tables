<?php

namespace App\Modules\Graph\Support\Aggregates;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Picks the aggregate dialect for the active database connection.
 */
class AggregateDialectResolver
{
    public function resolve(): AggregateDialect
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => new PostgresAggregateDialect,
            'sqlite' => new SqliteAggregateDialect,
            default => throw new RuntimeException('No AggregateDialect for driver "'.DB::connection()->getDriverName().'".'),
        };
    }
}
