<?php

use App\Modules\Graph\Support\Aggregates\SqliteMedian;
use Pdo\Sqlite;

/** graph_median over $values, run on a bare connection. */
function sqliteMedianOf(array $values): mixed
{
    $pdo = new Sqlite('sqlite::memory:');
    SqliteMedian::register($pdo);
    $pdo->exec('CREATE TABLE t (v)');
    $insert = $pdo->prepare('INSERT INTO t VALUES (?)');
    foreach ($values as $value) {
        $insert->bindValue(1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->execute();
    }

    return $pdo->query('SELECT graph_median(v) FROM t')->fetchColumn();
}

it('is null over an empty set or nothing but nulls', function (array $values) {
    expect(sqliteMedianOf($values))->toBeNull();
})->with(['empty' => [[]], 'only nulls' => [[null, null]]]);

it('skips nulls and takes the middle value, or the mean of the two middle ones', function (array $values, float $median) {
    expect(sqliteMedianOf($values))->toBe($median);
})->with([
    'odd' => [[30, null, 10, 20], 20.0],
    'even' => [[40, 10, null, 30, 20], 25.0],
]);
