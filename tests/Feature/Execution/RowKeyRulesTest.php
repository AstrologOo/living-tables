<?php

use Illuminate\Support\Facades\Http;

it('uses row_key_field configured on extract for source rows', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [
            ['sku' => 'A1', 'price' => 10],
            ['sku' => 'B2', 'price' => 20],
        ],
    ]), 200)]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ]);

    expect(array_column($rows, 'row_key'))->toBe(['A1', 'B2']);
});

it('lets transform steps inherit row_key from their input rows', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [
            ['sku' => 'A1', 'price' => 10],
            ['sku' => 'B2', 'price' => 5],
        ],
    ]), 200)]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 1],
        ['op' => 'map', 'fields' => ['sku' => 'sku', 'price' => 'price']],
    ]);

    // A trailing sort isn't executed, so source order stays.
    expect(array_column($rows, 'row_key'))->toBe(['A1', 'B2']);
});

it('degrades rows with no row_key to row_key = row_hash of their data', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'price' => 10]],
    ]), 200)]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'], // no row_key_field
    ]);

    $row = $rows[0];
    $sortedData = $row['data'];
    ksort($sortedData);

    expect($row['row_key'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($row['row_key'])->toBe(hash('sha256', json_encode($sortedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
});

it('gives identical rows the same degraded row_key, so unchanged content is not seen as a new row', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'price' => 10]],
    ]), 200)]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ];

    [, $firstRows] = runPipeline($dsl, 'node-a');
    [, $secondRows] = runPipeline($dsl, 'node-b');

    expect($firstRows[0]['row_key'])->toBe($secondRows[0]['row_key']);
});
