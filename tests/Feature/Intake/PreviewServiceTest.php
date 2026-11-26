<?php

use App\Models\User;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Intake\PreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeUsageLimiter;

uses(RefreshDatabase::class);

it('runs a candidate config against the current parent data without saving anything', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    (new ResultStore)->store($parent, new RowSet([
        ['row_key' => '1', 'data' => ['price' => 10]],
        ['row_key' => '2', 'data' => ['price' => 200]],
    ]));

    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child',
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ], [$parent->id]);
    $originalDslConfig = $node->fresh()->dsl_config;
    $originalStatus = $node->fresh()->status;

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore, isBackground: false);
    $preview = new PreviewService($ctx);

    $result = $preview->preview($node, [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 100],
    ]);

    expect($result->rows())->toBe([
        ['row_key' => '2', 'data' => ['price' => 200]],
    ]);

    // Nothing was persisted: no new version, config/status untouched.
    $fresh = $node->fresh();
    expect($fresh->dsl_config)->toBe($originalDslConfig)
        ->and($fresh->status)->toBe($originalStatus)
        ->and($fresh->current_version_id)->toBeNull()
        ->and($fresh->versions()->count())->toBe(0);
});

it('records a preview run_stats row with the real tokens an llm step spent, version_id null', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    (new ResultStore)->store($parent, new RowSet([
        ['row_key' => '1', 'data' => ['price' => 10]],
    ]));

    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child',
        'dsl_config' => [], ], [$parent->id]);

    $ctx = new ExecutionContext(
        new GraphParentDataProvider,
        new FakeLlmProvider(tokensPerCall: 42),
        new FakeDocumentParser,
        new FakeLlmCacheRepository,
        new FakeUsageLimiter,
        new GraphRowStore,
        isBackground: false,
    );

    (new PreviewService($ctx))->preview($node, [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['price'], 'output_field' => 'label', 'model_tier' => 'cheap'],
    ]);

    $stat = RunStat::query()->where('node_id', $node->id)->sole();

    expect($stat->status)->toBe('preview')
        ->and($stat->version_id)->toBeNull()
        ->and($stat->tokens_spent)->toBe(42)
        ->and($stat->rows_total)->toBe(1);
});

it('still records tokens already spent when a later step in the same preview throws', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    (new ResultStore)->store($parent, new RowSet([
        ['row_key' => '1', 'data' => ['price' => 10]],
    ]));

    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child',
        'dsl_config' => [], ], [$parent->id]);

    $ctx = new ExecutionContext(
        new GraphParentDataProvider,
        new FakeLlmProvider(tokensPerCall: 42),
        new FakeDocumentParser,
        new FakeLlmCacheRepository,
        new FakeUsageLimiter,
        new GraphRowStore,
        isBackground: false,
    );

    expect(fn () => (new PreviewService($ctx))->preview($node, [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['price'], 'output_field' => 'label', 'model_tier' => 'cheap'],
        ['op' => 'map', 'fields' => ['bad' => ['expr' => 'totally_undefined_identifier']]],
    ]))->toThrow(PipelineException::class);

    $stat = RunStat::query()->where('node_id', $node->id)->sole();

    expect($stat->status)->toBe('preview_failed')
        ->and($stat->tokens_spent)->toBe(42) // the llm step's real spend, before the map step blew up
        // A failed preview leaves no draft rows.
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(0);
});

it('rejects a config that references a node outside the declared parents', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    $stranger = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Stranger', 'dsl_config' => [],
    ]);
    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore, isBackground: false);
    $preview = new PreviewService($ctx);

    expect(fn () => $preview->preview($node, [
        ['op' => 'parent', 'node' => $stranger->id],
    ]))->toThrow(DslException::class, $stranger->id);
});

// trailing sort, unique and limit are applied to the preview

it('applies a trailing sort/unique/limit to the preview result instead of running it through the pipeline', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    (new ResultStore)->store($parent, new RowSet([
        ['row_key' => '1', 'data' => ['sku' => 'A1', 'category' => 'shoes', 'price' => 50]],
        ['row_key' => '2', 'data' => ['sku' => 'A2', 'category' => 'shoes', 'price' => 90]],
        ['row_key' => '3', 'data' => ['sku' => 'A3', 'category' => 'hats', 'price' => 20]],
    ]));

    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore, isBackground: false);

    $result = (new PreviewService($ctx))->preview($node, [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'sort', 'field' => 'price', 'order' => 'desc'],
        ['op' => 'unique', 'field' => 'category'],
        ['op' => 'limit', 'count' => 1],
    ]);

    // unique(category), then sort by price descending, then limit 1; key order may differ on Postgres.
    expect($result->rows())->toEqualCanonicalizing([
        ['row_key' => '1', 'data' => ['sku' => 'A1', 'category' => 'shoes', 'price' => 50]],
    ]);

    $stat = RunStat::query()->where('node_id', $node->id)->sole();
    expect($stat->rows_total)->toBe(1);
});
