<?php

use App\Modules\Execution\Operations\UniqueOperation;
use App\Modules\Execution\RowBatch;

it('keeps the first row for each distinct field value', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['sku' => 'A']],
        ['row_key' => '2', 'data' => ['sku' => 'B']],
        ['row_key' => '3', 'data' => ['sku' => 'A']],
    ]);

    $result = (new UniqueOperation)->apply($input, ['field' => 'sku'], executionContext());

    expect(array_column($result->rows(), 'row_key'))->toBe(['1', '2']);
});
