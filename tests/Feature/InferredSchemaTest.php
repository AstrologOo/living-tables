<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Providers\GraphLlmCacheRepository;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Shared\Adapters\NullDocumentParser;
use App\Modules\Shared\Adapters\NullLlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const SCHEMA_PIPELINE = [
    ['op' => 'webhook_source'],
    ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
];

/** A signed-in user's node with $pipeline applied through the API. */
function appliedWebhookNode(array $pipeline = SCHEMA_PIPELINE): Node
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [], 'trigger' => 'webhook']);
    test()->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $pipeline])->assertOk();

    return $node->fresh();
}

/** One worker run over $items. */
function runWith(Node $node, array $items): Node
{
    $node->fresh()->update(['status' => 'stale', 'webhook_payload' => ['items' => $items]]);
    $ctx = new ExecutionContext(new GraphParentDataProvider, new NullLlmProvider, new NullDocumentParser, new GraphLlmCacheRepository, new GraphUsageLimiter, new GraphRowStore);
    $scheduler = new SchedulerService;
    (new NodeRecalculator($scheduler, new ResultStore, new PipelineRunner, Mockery::mock(NodeEventPublisher::class)->shouldIgnoreMissing()))->claimAndProcess($ctx);

    return $node->fresh();
}

it('serves the default view, filters and sorting right after the first run, with nothing confirmed', function () {
    $node = appliedWebhookNode([...SCHEMA_PIPELINE, ['op' => 'sort', 'field' => 'price', 'order' => 'desc']]);

    $node = runWith($node, [['sku' => 'A', 'price' => 9], ['sku' => 'B', 'price' => 10]]);

    $view = DB::table('node_views')->where('node_id', $node->id)->value('id');
    expect($this->getJson("/api/views/{$view}/rows")->assertOk()->json('data.*.sku'))->toBe(['B', 'A'])
        ->and($this->getJson("/api/nodes/{$node->id}/rows?sort=price&filter[price][cmp]=>&filter[price][value]=9")->assertOk()->json('data.*.sku'))->toBe(['B'])
        ->and($node->columns_schema['price'])->toBe(['name' => 'price', 'type' => 'number'])
        ->and($node->columns_schema_confirmed)->toBeFalse();
});

it('follows the result while unconfirmed, without ever reporting drift', function () {
    $node = runWith(appliedWebhookNode(), [['sku' => 'A', 'price' => 9]]);

    $node = runWith($node, [['sku' => 'A', 'title' => 'renamed']]);

    expect($node->status)->toBe('fresh')
        ->and(array_keys($node->columns_schema))->toEqualCanonicalizing(['sku', 'title']);
});

it('calls a column with mixed values a string, so sorting it cannot fail', function () {
    $node = runWith(appliedWebhookNode(), [['sku' => 'A', 'price' => 9], ['sku' => 'B', 'price' => 'on request']]);

    expect($node->columns_schema['price']['type'])->toBe('string');
    $this->getJson("/api/nodes/{$node->id}/rows?sort=price")->assertOk();
});

it('keeps the known columns when a run returns no rows', function () {
    $node = runWith(appliedWebhookNode(), [['sku' => 'A']]);

    $node = runWith($node, []);

    expect(array_keys($node->columns_schema))->toBe(['sku']);
});

it('fills a missing schema even when the content did not change', function () {
    $node = runWith(appliedWebhookNode(), [['sku' => 'A']]);
    $node->update(['columns_schema' => null]);

    $node = runWith($node, [['sku' => 'A']]);

    expect(array_keys($node->columns_schema))->toBe(['sku']);
});

it('never overwrites a confirmed schema, and checks drift against it', function () {
    $node = runWith(appliedWebhookNode(), [['sku' => 'A', 'price' => 9]]);
    $confirmed = ['sku' => ['name' => 'Article', 'type' => 'string'], 'price' => ['name' => 'Price', 'type' => 'number']];
    $this->postJson("/api/nodes/{$node->id}/columns-schema", ['columns_schema' => $confirmed])
        ->assertOk()->assertJsonPath('columns_schema_confirmed', true);

    $node = runWith($node, [['sku' => 'B', 'price' => 1, 'extra' => 1]]);
    expect($node->status)->toBe('fresh')->and($node->columns_schema)->toEqualCanonicalizing($confirmed);

    expect(runWith($node, [['sku' => 'B']])->status)->toBe('schema_drift');
});

it('treats schemas saved before this column as confirmed', function () {
    $user = User::factory()->create();
    $attrs = ['user_id' => $user->id, 'type' => 'source', 'dsl_config' => []];
    $with = (new GraphService)->createNode([...$attrs, 'name' => 'with', 'columns_schema' => ['a' => ['name' => 'a', 'type' => 'string']]]);
    $without = (new GraphService)->createNode([...$attrs, 'name' => 'without']);
    $migration = require app_path('Modules/Graph/Database/migrations/2026_11_26_100019_add_columns_schema_confirmed_to_nodes_table.php');
    $migration->down();

    $migration->up();

    expect($with->fresh()->columns_schema_confirmed)->toBeTrue()
        ->and($without->fresh()->columns_schema_confirmed)->toBeFalse();
});
