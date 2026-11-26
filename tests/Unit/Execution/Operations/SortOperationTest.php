<?php

use App\Modules\Execution\Operations\SortOperation;
use App\Modules\Execution\RowBatch;

it('sorts ascending by default', function () {
    $input = RowBatch::fromRows([
        ['row_key' => 'b', 'data' => ['n' => 2]],
        ['row_key' => 'a', 'data' => ['n' => 1]],
        ['row_key' => 'c', 'data' => ['n' => 3]],
    ]);

    $result = (new SortOperation)->apply($input, ['field' => 'n'], executionContext());

    expect(array_column($result->rows(), 'row_key'))->toBe(['a', 'b', 'c']);
});

it('sorts descending', function () {
    $input = RowBatch::fromRows([
        ['row_key' => 'a', 'data' => ['n' => 1]],
        ['row_key' => 'c', 'data' => ['n' => 3]],
        ['row_key' => 'b', 'data' => ['n' => 2]],
    ]);

    $result = (new SortOperation)->apply($input, ['field' => 'n', 'order' => 'desc'], executionContext());

    expect(array_column($result->rows(), 'row_key'))->toBe(['c', 'b', 'a']);
});
