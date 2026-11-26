<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\Services\WorkerWaker;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Shared\Contracts\LlmProvider;
use App\Modules\Shared\Support\SafeUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Fakes\RecordingLlmProvider;
use Tests\Support\Fakes\StubDnsSafeUrlGuard;

uses(RefreshDatabase::class);

beforeEach(function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));
    $channel->getConnection()->close();
});

// --- store() ---

it('creates a node shell with edges to the given parents', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);

    $response = $this->postJson('/api/nodes', [
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'Child',
        'parent_ids' => [$parent->id],
    ])->assertCreated();

    $node = Node::find($response->json('id'));
    expect($node->name)->toBe('Child')
        ->and($node->dsl_config)->toBe([])
        ->and($node->parents()->pluck('nodes.id')->all())->toBe([$parent->id]);
});

it('rejects an invalid store request with 422', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/nodes', ['name' => 'Missing type'])
        ->assertStatus(422);
});

it('store requires authentication', function () {
    $this->postJson('/api/nodes', ['type' => 'source', 'name' => 'A'])->assertStatus(401);
});

it('user_id is always the authenticated user, never a client-supplied value', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/nodes', [
        'type' => 'source', 'name' => 'A', 'user_id' => $user->id + 999,
    ])->assertCreated();

    expect(Node::find($response->json('id'))->user_id)->toBe($user->id);
});

it('rejects a parent_id that belongs to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $parent = (new GraphService)->createNode([
        'user_id' => $owner->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);

    Sanctum::actingAs($other);
    $this->postJson('/api/nodes', [
        'type' => 'transform', 'name' => 'Child', 'parent_ids' => [$parent->id],
    ])->assertNotFound();
});

// webhook and schedule triggers

it('creates a webhook-triggered source with a generated secret, returned once', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Webhook source', 'trigger' => 'webhook',
    ])->assertCreated();

    $node = Node::find($response->json('id'));

    expect($node->trigger)->toBe('webhook')
        ->and($node->webhook_secret)->not->toBeNull()
        ->and(strlen($node->webhook_secret))->toBeGreaterThanOrEqual(40)
        ->and($node->dsl_config)->toBe([]) // still just a shell, same as every other node
        ->and($response->json('webhook_url'))->toContain("/webhooks/{$node->id}/{$node->webhook_secret}");
});

it('never exposes webhook_secret in plain JSON serialization, only via webhook_url on creation', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $createResponse = $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Webhook source', 'trigger' => 'webhook',
    ])->assertCreated();

    // The raw field itself must never appear in the response body, even
    // though webhook_url (which embeds the same secret) does.
    expect($createResponse->json('webhook_secret'))->toBeNull();

    $node = Node::find($createResponse->json('id'));
    $applyResponse = $this->postJson("/api/nodes/{$node->id}/apply", [
        'dsl_config' => [['op' => 'webhook_source'], ['op' => 'extract', 'path' => '$.items[*]']],
    ])->assertOk();

    expect($applyResponse->json('node.webhook_secret'))->toBeNull();
});

it('creates a schedule-triggered source with a valid cron expression', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Polled hourly', 'trigger' => 'schedule', 'schedule' => '0 * * * *',
    ])->assertCreated();

    $node = Node::find($response->json('id'));
    expect($node->trigger)->toBe('schedule')
        ->and($node->schedule)->toBe('0 * * * *');
});

it('rejects a malformed cron expression for schedule', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Bad cron', 'trigger' => 'schedule', 'schedule' => 'not a cron',
    ])->assertStatus(422);
});

it('rejects trigger: schedule without a schedule value', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Missing cron', 'trigger' => 'schedule',
    ])->assertStatus(422);
});

it('rejects a trigger on a transform node', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/nodes', [
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Bad', 'trigger' => 'webhook',
    ])->assertStatus(422);
});

// --- preview() ---

it('preview generates a DSL from a phrase and runs it without saving anything', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'цена', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_price' => 1500]],
        ['row_key' => 'r2', 'data' => ['col_price' => 500]],
    ]));

    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $llm = new RecordingLlmProvider([
        [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'filter', 'field' => 'цена', 'cmp' => '>', 'value' => 1000],
        ],
    ]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'дороже 1000'])
        ->assertOk();

    expect($response->json('dsl_config'))->toBe([
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'filter', 'field' => 'col_price', 'cmp' => '>', 'value' => 1000],
    ])->and($response->json('rows'))->toBe([
        ['row_key' => 'r1', 'data' => ['col_price' => 1500]],
    ]);

    expect($node->fresh()->dsl_config)->toBe([]); // nothing saved
});

it('preview with a phrase records the real tokens DslGenerator spent as a run_stats row', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'цена', 'type' => 'number']],
    ]);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $llm = new RecordingLlmProvider([
        [['op' => 'filter', 'field' => 'цена', 'cmp' => '>', 'value' => 1000]],
    ], tokensPerCall: 42);
    $this->app->instance(LlmProvider::class, $llm);

    $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'дороже 1000'])->assertOk();

    $stat = RunStat::query()->where('node_id', $node->id)->where('status', 'dsl_generation')->sole();

    expect($stat->version_id)->toBeNull()
        ->and($stat->tokens_spent)->toBe(42);
});

it('preview with a phrase still records tokens spent even when DslGenerator ultimately fails validation', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $stillInvalid = [['op' => 'filter', 'field' => 'price']]; // always missing cmp/value
    $llm = new RecordingLlmProvider([$stillInvalid, $stillInvalid], tokensPerCall: 42);
    $this->app->instance(LlmProvider::class, $llm);

    $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'дороже 1000'])->assertStatus(422);

    $stat = RunStat::query()->where('node_id', $node->id)->where('status', 'dsl_generation_failed')->sole();

    expect($stat->tokens_spent)->toBe(84); // both attempts happened
});

it('preview includes an inferred_columns_schema draft alongside the actual columns_schema', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['sku' => 'A1', 'price' => 10]],
    ]));

    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ])->assertOk();

    $columns = [
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ];

    // The first is the parent's schema, inferred by its run; the second is derived from the preview's own rows.
    expect($response->json('columns_schema'))->toEqual($columns)
        ->and($response->json('inferred_columns_schema'))->toBe($columns);
});

it('preview flags suggests_view for a dsl_config with no enrichment step', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['price' => 10]],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
        ],
    ])->assertOk();

    expect($response->json('suggests_view'))->toBeTrue();
});

it('preview does not flag suggests_view when the config has an enrichment step', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
        'columns_schema' => ['price' => ['name' => 'price', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['price' => 10]],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $llm = new RecordingLlmProvider([[
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'map', 'fields' => ['doubled' => ['expr' => 'price * 2']]],
    ]]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'удвой цену'])
        ->assertOk();

    expect($response->json('suggests_view'))->toBeFalse();
});

// warnings for steps that empty the result; only phrase-generated configs are retried

it('preview flags a warning when a hand-edited dsl_config wipes every row at one step', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['price' => 10]],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 999],
        ],
    ])->assertOk();

    expect($response->json('rows'))->toBe([])
        ->and($response->json('warnings'))->toHaveCount(1)
        ->and($response->json('warnings.0'))->toContain('filter');
});

it('preview does not warn when the result is empty because the source itself had nothing', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ])->assertOk();

    expect($response->json('warnings'))->toBe([]);
});

it('preview retries DSL generation with feedback when the first attempt empties the result, and stops once it succeeds', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'цена', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_price' => 1500]],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $emptying = [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'filter', 'field' => 'цена', 'cmp' => '>', 'value' => 999999],
    ];
    $working = [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'filter', 'field' => 'цена', 'cmp' => '>', 'value' => 1000],
    ];
    $llm = new RecordingLlmProvider([$emptying, $working]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'дороже 1000'])
        ->assertOk();

    expect($response->json('warnings'))->toBe([])
        ->and($response->json('rows'))->toBe([['row_key' => 'r1', 'data' => ['col_price' => 1500]]])
        ->and($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('Предыдущая попытка дала пустой результат');
});

it('preview stops retrying after the attempt cap and returns the last result with warnings instead of erroring', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'цена', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_price' => 1500]],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $alwaysEmptying = [
        ['op' => 'parent', 'node' => $parent->id],
        ['op' => 'filter', 'field' => 'цена', 'cmp' => '>', 'value' => 999999],
    ];
    $llm = new RecordingLlmProvider([$alwaysEmptying]); // repeats forever if asked
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", ['phrase' => 'дороже 1000'])
        ->assertOk();

    expect($response->json('rows'))->toBe([])
        ->and($response->json('warnings'))->toHaveCount(1)
        ->and($llm->callCount())->toBe(3); // capped, not unbounded
});

it('confirmColumnsSchema persists the given schema without touching dsl_config or status', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $statusBefore = $node->fresh()->status;

    $response = $this->postJson("/api/nodes/{$node->id}/columns-schema", [
        'columns_schema' => ['sku' => ['name' => 'sku', 'type' => 'string']],
    ])->assertOk();

    expect($response->json('columns_schema'))->toBe(['sku' => ['name' => 'sku', 'type' => 'string']])
        ->and($node->fresh()->columns_schema)->toBe(['sku' => ['name' => 'sku', 'type' => 'string']])
        ->and($node->fresh()->status)->toBe($statusBefore);
});

it('confirmColumnsSchema rejects a schema entry missing a name', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $this->postJson("/api/nodes/{$node->id}/columns-schema", [
        'columns_schema' => ['sku' => ['type' => 'string']],
    ])->assertStatus(422);
});

it('preview runs a hand-supplied dsl_config directly, skipping DslGenerator', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_a' => 'x']],
    ]));
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ])->assertOk();

    expect($response->json('rows'))->toBe([['row_key' => 'r1', 'data' => ['col_a' => 'x']]]);
});

it('preview rejects a config referencing a node outside the declared parents', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $stranger = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Stranger', 'dsl_config' => [],
    ]);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ]);

    $this->postJson("/api/nodes/{$node->id}/preview", [
        'dsl_config' => [['op' => 'parent', 'node' => $stranger->id]],
    ])->assertStatus(422);
});

it('preview requires either phrase or dsl_config', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ]);

    $this->postJson("/api/nodes/{$node->id}/preview", [])->assertStatus(422);
});

// --- apply() ---

it('apply saves the dsl_config, marks the node stale, and wakes the worker', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'fresh']);

    $config = [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']];

    $response = $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $config])
        ->assertOk();

    expect($response->json('changed'))->toBeTrue()
        ->and($node->fresh()->dsl_config)->toBe($config)
        ->and($node->fresh()->status)->toBe('stale');

    $channel = (new RabbitMqTopology)->connect();
    $message = null;
    for ($i = 0; $i < 20 && $message === null; $i++) {
        $message = $channel->basic_get(config('rabbitmq.queue'));
        if ($message === null) {
            usleep(50_000);
        }
    }
    $channel->getConnection()->close();

    expect($message)->not->toBeNull()
        ->and(json_decode($message->getBody(), true))->toBe(['node_id' => $node->id]);
});

it('apply is a no-op when the config is unchanged: no wake-up, changed=false', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $config = [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $config,
    ]);

    $response = $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $config])
        ->assertOk();

    expect($response->json('changed'))->toBeFalse();
});

it('apply with an unchanged config restarts a failed node', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $config = [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $config,
    ]);
    $node->update(['status' => 'failed', 'consecutive_failures' => 3, 'retry_at' => now()->addMinute()]);
    $this->mock(WorkerWaker::class)->shouldReceive('wake')->once()->with($node->id);

    $response = $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $config])
        ->assertOk();

    expect($response->json('changed'))->toBeFalse()
        ->and($response->json('node.status'))->toBe('stale')
        ->and($node->fresh()->only(['status', 'consecutive_failures', 'retry_at']))
        ->toBe(['status' => 'stale', 'consecutive_failures' => 0, 'retry_at' => null])
        ->and(DB::table('node_config_versions')->where('node_id', $node->id)->count())->toBe(1);
});

it('apply with an unchanged config leaves a node in any other status alone', function (string $status) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $config = [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $config,
    ]);
    $state = [
        'status' => $status,
        'claim_token' => $status === 'processing' ? (string) Str::uuid() : null,
        'consecutive_failures' => 2,
    ];
    $node->update($state);
    $this->mock(WorkerWaker::class)->shouldNotReceive('wake');

    $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $config])->assertOk();

    expect($node->fresh()->only(array_keys($state)))->toBe($state);
})->with(['fresh', 'stale', 'processing', 'schema_drift']);

it('apply rejects a malformed dsl_config with 422', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $this->postJson("/api/nodes/{$node->id}/apply", [
        'dsl_config' => [['op' => 'filter', 'field' => 'x']], // missing cmp/value
    ])->assertStatus(422);

    expect($node->fresh()->dsl_config)->toBe([]);
});

// --- configVersions() / rollback() ---

it('configVersions lists the node\'s dsl_config history, newest first', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $graph = new GraphService;
    $configA = [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']];
    $configB = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

    $node = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $configA]);
    $graph->updateDslConfig($node, $configB);

    $response = $this->getJson("/api/nodes/{$node->id}/config-versions")->assertOk();

    expect($response->json())->toHaveCount(2)
        ->and($response->json('0.dsl_config'))->toBe($configB) // newest first
        ->and($response->json('1.dsl_config'))->toBe($configA);
});

it('rollback restores an old config, marks the node stale, and wakes the worker', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $graph = new GraphService;
    $configA = [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']];
    $configB = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

    $node = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $configA]);
    $firstVersionId = NodeConfigVersion::query()->where('node_id', $node->id)->sole()->id;
    $graph->updateDslConfig($node, $configB);
    $node->update(['status' => 'fresh']);

    $response = $this->postJson("/api/nodes/{$node->id}/config-versions/{$firstVersionId}/rollback")
        ->assertOk();

    expect($response->json('changed'))->toBeTrue()
        ->and($node->fresh()->dsl_config)->toBe($configA)
        ->and($node->fresh()->status)->toBe('stale');

    $channel = (new RabbitMqTopology)->connect();
    $message = null;
    for ($i = 0; $i < 20 && $message === null; $i++) {
        $message = $channel->basic_get(config('rabbitmq.queue'));
        if ($message === null) {
            usleep(50_000);
        }
    }
    $channel->getConnection()->close();

    expect($message)->not->toBeNull()
        ->and(json_decode($message->getBody(), true))->toBe(['node_id' => $node->id]);
});

it('rollback returns 404 for a version id that does not belong to the node', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $graph = new GraphService;

    $nodeA = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $nodeB = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'B', 'dsl_config' => []]);
    $versionOfB = NodeConfigVersion::query()->where('node_id', $nodeB->id)->sole();

    $this->postJson("/api/nodes/{$nodeA->id}/config-versions/{$versionOfB->id}/rollback")
        ->assertNotFound();
});

// other users' nodes answer 404

it('preview/apply/columns-schema/config-versions/rollback all 404 for a node owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $graph = new GraphService;
    $node = $graph->createNode(['user_id' => $owner->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $versionId = NodeConfigVersion::query()->where('node_id', $node->id)->sole()->id;

    Sanctum::actingAs($other);

    $this->postJson("/api/nodes/{$node->id}/preview", ['dsl_config' => []])->assertNotFound();
    $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => []])->assertNotFound();
    $this->postJson("/api/nodes/{$node->id}/columns-schema", ['columns_schema' => []])->assertNotFound();
    $this->getJson("/api/nodes/{$node->id}/config-versions")->assertNotFound();
    $this->postJson("/api/nodes/{$node->id}/config-versions/{$versionId}/rollback")->assertNotFound();
});

// --- destroy() ---

it('destroy removes a node with no dependents', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $this->deleteJson("/api/nodes/{$node->id}")->assertStatus(204);

    expect(Node::find($node->id))->toBeNull();
});

it('destroy refuses to remove a node other nodes still depend on', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $graph = new GraphService;
    $parent = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => []]);
    $graph->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => []], [$parent->id]);

    $this->deleteJson("/api/nodes/{$parent->id}")->assertStatus(422);

    expect(Node::find($parent->id))->not->toBeNull();
});

it('destroy returns 404 for a node owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $owner->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    Sanctum::actingAs($other);
    $this->deleteJson("/api/nodes/{$node->id}")->assertNotFound();
    expect(Node::find($node->id))->not->toBeNull();
});

// fetch errors don't reveal hosts or IPs

function nodeFetchingBlockedHost(): Node
{
    app()->instance(SafeUrlGuard::class, new StubDnsSafeUrlGuard(['internal.example' => ['10.1.2.3']]));
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'http://internal.example/secret', 'format' => 'json']],
    ]);
}

it('preview reports a blocked source without its host or IP, and logs the details', function () {
    $node = nodeFetchingBlockedHost();
    Log::spy();

    $response = $this->postJson("/api/nodes/{$node->id}/preview", ['dsl_config' => $node->dsl_config])
        ->assertStatus(422);

    expect($response->json('error'))->toBe('Шаг 0 (fetch): адрес источника запрещён')
        ->and($response->getContent())->not->toContain('10.1.2.3')
        ->and($response->getContent())->not->toContain('internal.example');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '10.1.2.3'))->once();
});

it('the failed notification of a blocked source carries no host or IP', function () {
    $node = nodeFetchingBlockedHost();
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, new PipelineRunner, new NodeEventPublisher($channel));

    $recalculator->claimAndProcess(app(ExecutionContext::class));

    $response = $this->getJson('/api/notifications')->assertOk();

    expect($response->json('0.payload'))->toBe(['message' => 'Шаг 0 (fetch): адрес источника запрещён'])
        ->and($response->getContent())->not->toContain('10.1.2.3')
        ->and($response->getContent())->not->toContain('internal.example')
        ->and($node->fresh()->status)->toBe('failed');
});

// a config change restarts the retry count

it('apply and rollback reset the failure counter and the retry delay', function (string $action) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $graph = new GraphService;
    $node = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']],
    ]);
    $original = NodeConfigVersion::query()->where('node_id', $node->id)->value('id');
    $changed = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

    if ($action === 'rollback') {
        $graph->updateDslConfig($node, $changed);
    }

    $node->update(['status' => 'failed', 'consecutive_failures' => 3, 'retry_at' => now()->addHour()]);

    $action === 'rollback'
        ? $this->postJson("/api/nodes/{$node->id}/config-versions/{$original}/rollback")->assertOk()
        : $this->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $changed])->assertOk();

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->consecutive_failures)->toBe(0)
        ->and($node->fresh()->retry_at)->toBeNull();
})->with(['apply', 'rollback']);
