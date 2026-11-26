<?php

use App\Modules\Execution\Operations\LimitOperation;
use App\Modules\Execution\RowBatch;

it('keeps only the first N rows', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => []],
        ['row_key' => '2', 'data' => []],
        ['row_key' => '3', 'data' => []],
    ]);

    $result = (new LimitOperation)->apply($input, ['count' => 2], executionContext());

    expect(array_column($result->rows(), 'row_key'))->toBe(['1', '2']);
});

it('is a no-op when the count exceeds the row count', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => []]]);

    $result = (new LimitOperation)->apply($input, ['count' => 10], executionContext());

    expect($result->count())->toBe(1);
});
