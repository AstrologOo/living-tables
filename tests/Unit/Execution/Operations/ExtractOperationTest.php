<?php

use App\Modules\Execution\Operations\ExtractOperation;
use App\Modules\Execution\RowBatch;

it('extracts rows from json via JSONPath', function () {
    $input = RowBatch::fromRows([[
        'row_key' => null,
        'data' => [
            '_raw' => ['items' => [['name' => 'A'], ['name' => 'B']]],
            '_format' => 'json',
        ],
    ]]);

    $result = (new ExtractOperation)->apply($input, ['path' => '$.items[*]'], executionContext());

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['name' => 'A']],
        ['row_key' => null, 'data' => ['name' => 'B']],
    ]);
});

it('wraps scalar json matches under a value key', function () {
    $input = RowBatch::fromRows([[
        'row_key' => null,
        'data' => ['_raw' => ['tags' => ['x', 'y']], '_format' => 'json'],
    ]]);

    $result = (new ExtractOperation)->apply($input, ['path' => '$.tags[*]'], executionContext());

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['value' => 'x']],
        ['row_key' => null, 'data' => ['value' => 'y']],
    ]);
});

it('extracts rows from html via a CSS selector', function () {
    $input = RowBatch::fromRows([[
        'row_key' => null,
        'data' => ['_raw' => '<ul><li>A</li><li>B</li></ul>', '_format' => 'html'],
    ]]);

    $result = (new ExtractOperation)->apply($input, ['path' => 'li'], executionContext());

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['value' => 'A']],
        ['row_key' => null, 'data' => ['value' => 'B']],
    ]);
});

it('passes through rows with no recognized raw format unchanged', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['x' => 1]]]);

    $result = (new ExtractOperation)->apply($input, ['path' => '$.anything'], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['x' => 1]]]);
});
