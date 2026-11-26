<?php

use App\Modules\Execution\Operations\FilterOperation;
use App\Modules\Execution\RowBatch;

dataset('filter cases', [
    '==' => [['a' => 1], '==', 1, true],
    '== (no match)' => [['a' => 1], '==', 2, false],
    '!=' => [['a' => 1], '!=', 2, true],
    '>' => [['a' => 5], '>', 3, true],
    '<' => [['a' => 2], '<', 3, true],
    '>=' => [['a' => 3], '>=', 3, true],
    '<=' => [['a' => 3], '<=', 3, true],
    'contains' => [['a' => 'hello world'], 'contains', 'world', true],
    'contains (no match)' => [['a' => 'hello'], 'contains', 'world', false],
]);

it('applies the comparator', function (array $data, string $cmp, mixed $value, bool $shouldMatch) {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => $data]]);

    $result = (new FilterOperation)->apply($input, ['field' => 'a', 'cmp' => $cmp, 'value' => $value], executionContext());

    expect($result->count())->toBe($shouldMatch ? 1 : 0);
})->with('filter cases');

// a boolean column compared with a string: PHP's `==` would treat true and "false" as equal

it('does not let a bool column match the opposite value spelled as a JSON string', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['completed' => true]],
        ['row_key' => '2', 'data' => ['completed' => false]],
    ]);

    $result = (new FilterOperation)->apply($input, ['field' => 'completed', 'cmp' => '==', 'value' => 'false'], executionContext());

    expect($result->rows())->toBe([
        ['row_key' => '2', 'data' => ['completed' => false]],
    ]);
});

it('does not mutate the input payload', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['a' => 1]],
        ['row_key' => '2', 'data' => ['a' => 2]],
    ]);

    $result = (new FilterOperation)->apply($input, ['field' => 'a', 'cmp' => '==', 'value' => 1], executionContext());

    expect($input->count())->toBe(2)
        ->and($result->count())->toBe(1);
});
