<?php

use App\Modules\Execution\Operations\MapOperation;
use App\Modules\Execution\RowBatch;

it('selects and renames fields', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['col_price' => 10, 'col_name' => 'Widget']]]);

    $result = (new MapOperation)->apply($input, ['fields' => ['name' => 'col_name']], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['name' => 'Widget']]]);
});

it('computes fields via expressions over col_id variables', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['price' => 10, 'qty' => 3]]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['total' => ['expr' => 'price * qty']],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['total' => 30]]]);
});

it('preserves row_key and does not mutate the input', function () {
    $input = RowBatch::fromRows([['row_key' => 'k1', 'data' => ['a' => 1]]]);

    $result = (new MapOperation)->apply($input, ['fields' => ['a' => 'a']], executionContext());

    expect($result->rows()[0]['row_key'])->toBe('k1')
        ->and($input->rows()[0]['data'])->toBe(['a' => 1]);
});

// extract_number

it('extract_number pulls the first number out of free text', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['note' => 'цена: 150 руб']]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['price' => ['expr' => 'extract_number(note)']],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['price' => 150]]]);
});

it('extract_number parses a negative decimal', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['note' => 'delta: -12.5 units']]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['delta' => ['expr' => 'extract_number(note)']],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['delta' => -12.5]]]);
});

it('extract_number returns null (not 0) when no number is present, distinguishable via a fallback formula', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['note' => 'no digits here', 'qty' => 3]]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['total' => ['expr' => "extract_number(note) === null ? 'Не найдено' : extract_number(note) * qty"]],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['total' => 'Не найдено']]]);
});

it('extract_number combines with another column via a formula when a number is present', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['note' => 'цена: 150 руб', 'qty' => 3]]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['total' => ['expr' => "extract_number(note) === null ? 'Не найдено' : extract_number(note) * qty"]],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['total' => 450]]]);
});

it('extract_number passes an already-numeric value through unchanged', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['n' => 42]]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['n2' => ['expr' => 'extract_number(n)']],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['n2' => 42]]]);
});

// is_fibonacci, including values far down the sequence

it('is_fibonacci recognizes members of the sequence beyond any plausible hardcoded ceiling', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['n' => 233]],
        ['row_key' => '2', 'data' => ['n' => 377]],
    ]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['fib' => ['expr' => 'is_fibonacci(n)']],
    ], executionContext());

    expect($result->rows()[0]['data']['fib'])->toBeTrue()
        ->and($result->rows()[1]['data']['fib'])->toBeTrue();
});

it('is_fibonacci rejects non-members, negatives, and non-integers', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['n' => 4]],
        ['row_key' => '2', 'data' => ['n' => 22]],
        ['row_key' => '3', 'data' => ['n' => -5]],
        ['row_key' => '4', 'data' => ['n' => 2.5]],
    ]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['fib' => ['expr' => 'is_fibonacci(n)']],
    ], executionContext());

    expect(array_column(array_column($result->rows(), 'data'), 'fib'))->toBe([false, false, false, false]);
});

// regex_extract and `matches`. Patterns inside expressions need doubled backslashes (four in this PHP source).

it('the built-in "matches" operator reports whether a pattern is found anywhere in the text', function () {
    $input = RowBatch::fromRows([
        ['row_key' => '1', 'data' => ['phone' => 'call 555-1234 now']],
        ['row_key' => '2', 'data' => ['phone' => 'no phone here']],
    ]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['has_phone' => ['expr' => "phone matches '/\\\\d{3}-\\\\d{4}/'"]],
    ], executionContext());

    expect($result->rows()[0]['data']['has_phone'])->toBe(1)
        ->and($result->rows()[1]['data']['has_phone'])->toBe(0);
});

it('regex_extract returns the whole match by default (group 0)', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['email' => 'user@gmail.com']]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['whole' => ['expr' => "regex_extract(email, '/@([\\\\w.]+)/')"]],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['whole' => '@gmail.com']]]);
});

it('regex_extract returns just the given capture group, not the whole match', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['email' => 'user@gmail.com']]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['domain' => ['expr' => "regex_extract(email, '/@([\\\\w.]+)/', 1)"]],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['domain' => 'gmail.com']]]);
});

it('regex_extract returns null when nothing matches', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['email' => 'not an email']]]);

    $result = (new MapOperation)->apply($input, [
        'fields' => ['domain' => ['expr' => "regex_extract(email, '/@([\\\\w.]+)/', 1)"]],
    ], executionContext());

    expect($result->rows())->toBe([['row_key' => '1', 'data' => ['domain' => null]]]);
});

it('regex_extract throws a clear error for a malformed pattern instead of silently returning null', function () {
    $input = RowBatch::fromRows([['row_key' => '1', 'data' => ['text' => 'x']]]);

    expect(fn () => (new MapOperation)->apply($input, [
        'fields' => ['x' => ['expr' => "regex_extract(text, '/unterminated')"]],
    ], executionContext()))->toThrow(InvalidArgumentException::class);
});
