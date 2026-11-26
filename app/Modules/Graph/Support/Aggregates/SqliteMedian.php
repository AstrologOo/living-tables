<?php

namespace App\Modules\Graph\Support\Aggregates;

use Pdo\Sqlite;

/**
 * Registered on sqlite connections because not every sqlite build has median(); it matches PERCENTILE_CONT(0.5).
 */
final class SqliteMedian
{
    public const FUNCTION = 'graph_median';

    public static function register(Sqlite $pdo): void
    {
        $pdo->createAggregate(
            self::FUNCTION,
            function (?array $values, int $row, mixed $value): array {
                $values ??= [];

                if ($value !== null) {
                    $values[] = $value;
                }

                return $values;
            },
            function (?array $values): ?float {
                if ($values === null || $values === []) {
                    return null;
                }

                sort($values, SORT_NUMERIC);
                $count = count($values);

                // Both indexes are the middle one when the count is odd.
                return ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2;
            },
            1,
        );
    }
}
