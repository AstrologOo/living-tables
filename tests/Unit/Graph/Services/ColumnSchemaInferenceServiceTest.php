<?php

use App\Modules\Graph\Services\ColumnSchemaInferenceService;

it('infers name and type from the first non-null value seen for each field', function () {
    $rows = [
        ['row_key' => 'r1', 'data' => ['sku' => 'A1', 'price' => 10, 'active' => true]],
        ['row_key' => 'r2', 'data' => ['sku' => 'A2', 'price' => 20, 'active' => false]],
    ];

    $schema = (new ColumnSchemaInferenceService)->infer($rows);

    expect($schema)->toBe([
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
        'active' => ['name' => 'active', 'type' => 'boolean'],
    ]);
});

it('defaults to string when every sampled value for a field is null', function () {
    $rows = [
        ['row_key' => 'r1', 'data' => ['note' => null]],
        ['row_key' => 'r2', 'data' => ['note' => null]],
    ];

    expect((new ColumnSchemaInferenceService)->infer($rows))->toBe([
        'note' => ['name' => 'note', 'type' => 'string'],
    ]);
});

it('unions fields across rows, not just the first row', function () {
    $rows = [
        ['row_key' => 'r1', 'data' => ['a' => 1]],
        ['row_key' => 'r2', 'data' => ['b' => 'x']],
    ];

    expect((new ColumnSchemaInferenceService)->infer($rows))->toBe([
        'a' => ['name' => 'a', 'type' => 'number'],
        'b' => ['name' => 'b', 'type' => 'string'],
    ]);
});

it('returns an empty schema for no rows', function () {
    expect((new ColumnSchemaInferenceService)->infer([]))->toBe([]);
});

it('takes the first non-null value even when an earlier row had null for the same field', function () {
    $rows = [
        ['row_key' => 'r1', 'data' => ['x' => null]],
        ['row_key' => 'r2', 'data' => ['x' => 5]],
    ];

    expect((new ColumnSchemaInferenceService)->infer($rows))->toBe([
        'x' => ['name' => 'x', 'type' => 'number'],
    ]);
});

it('calls a column whose values differ in type a string', function () {
    $rows = [
        ['row_key' => 'r1', 'data' => ['x' => 5, 'y' => true]],
        ['row_key' => 'r2', 'data' => ['x' => 'five', 'y' => 1]],
        ['row_key' => 'r3', 'data' => ['x' => 6, 'y' => true]],
    ];

    expect((new ColumnSchemaInferenceService)->infer($rows))->toBe([
        'x' => ['name' => 'x', 'type' => 'string'],
        'y' => ['name' => 'y', 'type' => 'string'],
    ]);
});
