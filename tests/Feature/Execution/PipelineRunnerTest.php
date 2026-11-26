<?php

use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Execution\RowBatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Fakes\FakeParentDataProvider;

it('runs a hand-written pipeline: fetch -> extract -> filter -> map', function () {
    Http::fake([
        '*' => Http::response(json_encode(['items' => [
            ['name' => 'A', 'price' => 10, 'qty' => 2],
            ['name' => 'B', 'price' => 3, 'qty' => 5],
            ['name' => 'C', 'price' => 7, 'qty' => 1],
        ]]), 200),
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
        ['op' => 'map', 'fields' => ['name' => 'name', 'total' => ['expr' => 'price * qty']]],
        // A trailing sort isn't executed, so source order stays.
    ];

    [$result, $rows] = runPipeline($dsl);

    // Without row_key_field the row hash is the key.
    expect(array_column($rows, 'data'))->toBe([
        ['name' => 'A', 'total' => 20],
        ['name' => 'C', 'total' => 7],
    ])
        ->and(array_column($rows, 'row_key'))->each->toMatch('/^[0-9a-f]{64}$/')
        ->and($result->count())->toBe(2);
});

it('tracks row counts crossing each step, for a caller to detect a step that silently wiped the result', function () {
    Http::fake([
        '*' => Http::response(json_encode(['items' => [
            ['name' => 'A', 'price' => 10, 'qty' => 2],
            ['name' => 'B', 'price' => 3, 'qty' => 5],
            ['name' => 'C', 'price' => 7, 'qty' => 1],
        ]]), 200),
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
        ['op' => 'map', 'fields' => ['name' => 'name', 'total' => ['expr' => 'price * qty']]],
    ];

    [$result] = runPipeline($dsl);
    $stats = $result->stepStats();

    expect($stats[1])->toBe(['op' => 'extract', 'rows_before' => 1, 'rows_after' => 3])
        ->and($stats[2])->toBe(['op' => 'filter', 'rows_before' => 3, 'rows_after' => 2])
        ->and($stats[3])->toBe(['op' => 'map', 'rows_before' => 2, 'rows_after' => 2]);
});

it('returns an empty result for an empty pipeline', function () {
    [$result, $rows] = runPipeline([]);

    expect($result->count())->toBe(0)
        ->and($rows)->toBe([]);
});

it('wraps an unknown operation in a PipelineException with the step index and op', function () {
    try {
        runPipeline([
            ['op' => 'filter', 'field' => 'x', 'cmp' => '==', 'value' => 1],
            ['op' => 'bogus_op'],
        ]);

        $this->fail('Expected PipelineException was not thrown.');
    } catch (PipelineException $e) {
        expect($e->step)->toBe(1)
            ->and($e->op)->toBe('bogus_op')
            ->and($e->getPrevious())->toBeInstanceOf(UnknownOperationException::class);
    }
});

it('wraps a failing operation in a PipelineException at the correct step', function () {
    $ctx = executionContext(new FakeParentDataProvider([
        'n1' => RowBatch::fromRows([['row_key' => '1', 'data' => ['x' => 1]]]),
    ]));

    try {
        runPipeline([
            ['op' => 'parent', 'node' => 'n1'],
            ['op' => 'filter', 'field' => 'x', 'cmp' => 'not_a_real_cmp', 'value' => 1],
        ], ctx: $ctx);

        $this->fail('Expected PipelineException was not thrown.');
    } catch (PipelineException $e) {
        expect($e->step)->toBe(1)
            ->and($e->op)->toBe('filter')
            ->and($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
});

// schema drift, checked against columns renamed by `map`

it('does not check drift when no columns_schema is set on the context', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['name' => 'A']]]), 200)]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ]);

    expect(array_column($rows, 'data'))->toBe([['name' => 'A']]);
});

it('passes when a map step renames a field to match the declared schema', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['id' => 1, 'title' => 'A']]]), 200)]);

    $ctx = executionContext();
    // Keys are col_ids, matching `map`'s output names.
    $ctx->setExpectedColumnsSchema([
        'issue_id' => ['name' => 'Issue ID', 'type' => 'number'],
        'title' => ['name' => 'Title', 'type' => 'string'],
    ]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
        ['op' => 'map', 'fields' => ['issue_id' => 'id', 'title' => 'title']],
    ], ctx: $ctx);

    expect(array_column($rows, 'data'))->toBe([['issue_id' => 1, 'title' => 'A']]);
});

it('throws SchemaDriftException at the last step when a used column is gone from every row', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $ctx = executionContext();
    $ctx->setExpectedColumnsSchema([
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ];

    try {
        runPipeline($dsl, ctx: $ctx);
        test()->fail('Expected PipelineException was not thrown.');
    } catch (PipelineException $e) {
        expect($e->step)->toBe(count($dsl) - 1)
            ->and($e->op)->toBe('schema_drift')
            ->and($e->getPrevious())->toBeInstanceOf(SchemaDriftException::class)
            ->and($e->getPrevious()->missingFields)->toBe(['price' => 'price']);
    }
});

it('throws SchemaDriftException when a field\'s value no longer matches its declared type', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1', 'price' => 'expensive']]]), 200)]);

    $ctx = executionContext();
    $ctx->setExpectedColumnsSchema([
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ]);

    expect(fn () => runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ], ctx: $ctx))->toThrow(PipelineException::class);
});

it('logs, but does not throw, when the final result has a field outside the declared schema', function () {
    // Log::spy() persists between tests.
    Log::clearResolvedInstance('log');
    Log::spy();

    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1', 'brand_new_field' => 'x']]]), 200)]);

    $ctx = executionContext();
    $ctx->setExpectedColumnsSchema(['sku' => ['name' => 'sku', 'type' => 'string']]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ], ctx: $ctx);

    expect(array_column($rows, 'data'))->toBe([['sku' => 'A1', 'brand_new_field' => 'x']]);
    Log::shouldHaveReceived('warning')->once();
});

it('treats a null value for a declared field as present, not missing (JSON legally omits it per-row)', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1', 'price' => null]]]), 200)]);

    $ctx = executionContext();
    $ctx->setExpectedColumnsSchema([
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ]);

    [, $rows] = runPipeline([
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ], ctx: $ctx);

    expect(array_column($rows, 'data'))->toBe([['sku' => 'A1', 'price' => null]]);
});

it('catches a vanished field at the map step, before a later filter can silently zero out the result', function () {
    // `map` would turn the missing `state` into nulls and `filter` would drop every row, so drift must be caught first.
    Http::fake(['*' => Http::response(json_encode(['items' => [['id' => 1, 'title' => 'A']]]), 200)]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
        ['op' => 'map', 'fields' => ['issue' => 'id', 'issue_state' => 'state']],
        ['op' => 'filter', 'field' => 'issue_state', 'cmp' => '==', 'value' => 'open'],
    ];

    try {
        runPipeline($dsl);
        test()->fail('Expected PipelineException was not thrown.');
    } catch (PipelineException $e) {
        expect($e->step)->toBe(2)
            ->and($e->op)->toBe('map')
            ->and($e->getPrevious())->toBeInstanceOf(SchemaDriftException::class)
            ->and($e->getPrevious()->missingFields)->toBe(['state' => 'state']);
    }
});

// paginated fetch: the chain runs once per page

it('runs the chain once per page a paginated fetch yields, writing every page\'s rows', function () {
    Http::fake([
        'https://api.test/items?page=1' => Http::response(json_encode(['items' => [
            ['sku' => 'A1', 'price' => 10],
        ]]), 200),
        'https://api.test/items?page=2' => Http::response(json_encode(['items' => [
            ['sku' => 'A2', 'price' => 20],
        ]]), 200),
        'https://api.test/items?page=3' => Http::response(json_encode(['items' => []]), 200),
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json', 'pagination' => [
            'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10,
        ]],
        ['op' => 'extract', 'path' => '$[*]', 'row_key_field' => 'sku'],
        ['op' => 'map', 'fields' => ['sku' => 'sku', 'price' => 'price']],
    ];

    [$result, $rows] = runPipeline($dsl);

    expect($result->count())->toBe(2)
        ->and(array_column($rows, 'data'))->toEqualCanonicalizing([
            ['sku' => 'A1', 'price' => 10],
            ['sku' => 'A2', 'price' => 20],
        ]);
});

it('reports the correct step index when a chain step fails on a later page, not just the first', function () {
    Http::fake([
        'https://api.test/items?page=1' => Http::response(json_encode(['items' => [
            ['sku' => 'A1', 'price' => 10],
        ]]), 200),
        'https://api.test/items?page=2' => Http::response(json_encode(['items' => [
            ['sku' => 'A2'], // no "price", so `filter` fails on this page only
        ]]), 200),
        'https://api.test/items?page=3' => Http::response(json_encode(['items' => []]), 200),
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://api.test/items', 'format' => 'json', 'pagination' => [
            'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10,
        ]],
        ['op' => 'extract', 'path' => '$[*]', 'row_key_field' => 'sku'],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
    ];

    try {
        runPipeline($dsl);
        test()->fail('Expected PipelineException was not thrown.');
    } catch (PipelineException $e) {
        expect($e->step)->toBe(2)
            ->and($e->op)->toBe('filter')
            ->and($e->getPrevious())->toBeInstanceOf(SchemaDriftException::class);
    }
});
