<?php

use App\Modules\Execution\RowBatch;

it('builds from rows and exposes them unchanged', function () {
    $rows = [
        ['row_key' => '1', 'data' => ['col_a' => 'x']],
        ['row_key' => '2', 'data' => ['col_a' => 'y']],
    ];

    $batch = RowBatch::fromRows($rows, ['col_a' => ['name' => 'A', 'type' => 'string']]);

    expect($batch->rows())->toBe($rows)
        ->and($batch->columnsSchema())->toBe(['col_a' => ['name' => 'A', 'type' => 'string']])
        ->and($batch->count())->toBe(2)
        ->and(count($batch))->toBe(2);
});

it('defaults to an empty columns schema', function () {
    $batch = RowBatch::fromRows([]);

    expect($batch->columnsSchema())->toBe([])
        ->and($batch->count())->toBe(0);
});

it('withRows returns a new instance without mutating the original', function () {
    $original = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['col_a' => 'x']],
    ], ['col_a' => ['type' => 'string']]);

    $updated = $original->withRows([
        ['row_key' => '1', 'data' => ['col_a' => 'x']],
        ['row_key' => '2', 'data' => ['col_a' => 'y']],
    ]);

    expect($updated)->not->toBe($original)
        ->and($original->count())->toBe(1)
        ->and($updated->count())->toBe(2)
        ->and($updated->columnsSchema())->toBe($original->columnsSchema());
});
