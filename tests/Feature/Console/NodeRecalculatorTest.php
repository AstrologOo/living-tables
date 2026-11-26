<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\ExpressionException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\Payload;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Exceptions\LockLostException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Models\NodeNotification;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Models\UserLimit;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\NodeRunStateService;
use App\Modules\Graph\Services\RabbitMqTopology;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\Services\WebhookIngestService;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Intake\PreviewService;
use App\Modules\Shared\Contracts\RealtimeNotifier;
use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Support\PublicErrorMessage;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeRealtimeNotifier;
use Tests\Support\Fakes\FakeUsageLimiter;

uses(RefreshDatabase::class);

function makeRecalculator(?int $maxRunSeconds = null): NodeRecalculator
{
    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $topology->declare($channel);

    return new NodeRecalculator(
        new SchedulerService,
        new ResultStore,
        new PipelineRunner,
        new NodeEventPublisher($channel),
        maxRunSeconds: $maxRunSeconds ?? 1800,
    );
}

function realExecutionContext(): ExecutionContext
{
    return new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore);
}

it('processes a ready node: stores a version and records the run', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'price' => 10]],
    ]), 200)]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    $recalculator = makeRecalculator();
    $claimed = (new SchedulerService)->claimReadyNode();

    expect($claimed->id)->toBe($node->id);

    $recalculator->processNode($claimed, realExecutionContext());

    $fresh = $node->fresh();
    expect($fresh->status)->toBe('fresh')
        ->and($fresh->currentVersion->rows()->count())->toBe(1);

    $runStat = RunStat::query()->where('node_id', $node->id)->where('status', 'success')->first();
    expect($runStat)->not->toBeNull()
        ->and($runStat->version_id)->toBe($fresh->current_version_id)
        ->and($runStat->rows_total)->toBe(1)
        ->and($runStat->rows_changed)->toBe(1); // first-ever version: one row added
});

it('publishes one wake-up for each child its new version turned stale', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $graph = new GraphService;
    $userId = User::factory()->create()->id;
    $parent = $graph->createNode([
        'user_id' => $userId, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);
    $attrs = ['user_id' => $userId, 'type' => 'transform', 'dsl_config' => []];
    $child = $graph->createNode([...$attrs, 'name' => 'fresh child'], [$parent->id]);
    $graph->createNode([...$attrs, 'name' => 'stale child'], [$parent->id]);
    $child->update(['status' => 'fresh']);

    $recalculator = makeRecalculator();
    $channel = (new RabbitMqTopology)->connect();
    $channel->queue_purge(config('rabbitmq.queue'));

    $recalculator->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());

    $message = null;
    for ($i = 0; $i < 20 && $message === null; $i++) {
        $message = $channel->basic_get(config('rabbitmq.queue'));
        if ($message === null) {
            usleep(50_000);
        }
    }

    expect($message)->not->toBeNull()
        ->and(json_decode($message->getBody(), true))->toBe(['node_id' => $child->id])
        ->and($channel->basic_get(config('rabbitmq.queue')))->toBeNull();

    $channel->getConnection()->close();
});

// realtime pushes to the browser

it('pushes a node.status_changed(fresh) event on a successful recompute', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1', 'price' => 10]]]), 200)]);
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    (makeRecalculator())->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());

    $pushed = collect($fake->sent)->firstWhere('event', 'node.status_changed');
    expect($pushed)->not->toBeNull()
        ->and($pushed['userId'])->toBe($user->id)
        ->and($pushed['payload'])->toBe(['node_id' => $node->id, 'status' => 'fresh']);
});

it('still pushes node.status_changed(fresh) when content is unchanged (no new version, still a real transition)', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);
    $user = User::factory()->create();
    $dsl = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $dsl,
    ]);
    $recalculator = makeRecalculator();
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());
    $node->fresh()->update(['status' => 'stale']);

    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());

    $pushed = collect($fake->sent)->firstWhere('event', 'node.status_changed');
    expect($pushed['payload'])->toBe(['node_id' => $node->id, 'status' => 'fresh']);
});

it('pushes node.status_changed(failed) via markFailed', function () {
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (makeRecalculator())->markFailed($node, new RuntimeException('boom'));

    $pushed = collect($fake->sent)->firstWhere('event', 'node.status_changed');
    expect($pushed['userId'])->toBe($user->id)
        ->and($pushed['payload'])->toBe(['node_id' => $node->id, 'status' => 'failed']);
});

it('pushes node.status_changed(schema_drift) via markSchemaDrift', function () {
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (makeRecalculator())->markSchemaDrift($node, new SchemaDriftException(['col_price' => 'price']));

    $pushed = collect($fake->sent)->firstWhere('event', 'node.status_changed');
    expect($pushed['userId'])->toBe($user->id)
        ->and($pushed['payload'])->toBe(['node_id' => $node->id, 'status' => 'schema_drift']);
});

it('appends no version when content is unchanged', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $user = User::factory()->create();
    $dsl = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $dsl,
    ]);

    $recalculator = makeRecalculator();
    $ctx = realExecutionContext();

    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);
    $versionsAfterFirst = $node->fresh()->versions()->count();

    // Recompute again with the same parent data.
    $node->fresh()->update(['status' => 'stale']);
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);

    expect($node->fresh()->versions()->count())->toBe($versionsAfterFirst)
        ->and($versionsAfterFirst)->toBe(1);
});

it('drainReadyNodes processes every currently-ready node', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'X']]]), 200)]);

    $user = User::factory()->create();
    $dsl = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];
    $graph = new GraphService;
    $a = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $dsl]);
    $b = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'B', 'dsl_config' => $dsl]);

    $processed = makeRecalculator()->drainReadyNodes(realExecutionContext());

    expect($processed)->toBe(2)
        ->and($a->fresh()->status)->toBe('fresh')
        ->and($b->fresh()->status)->toBe('fresh');
});

it('skips a node whose Redis lock is already held', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    $claimed = (new SchedulerService)->claimReadyNode();

    $externalLock = Cache::store('redis')->lock("node:{$claimed->id}", 60);
    expect($externalLock->get())->toBeTrue();

    try {
        makeRecalculator()->processNode($claimed, realExecutionContext());

        expect($node->fresh()->current_version_id)->toBeNull();
    } finally {
        $externalLock->release();
    }
});

it('drainReadyNodes recovers from a failing node instead of crashing or looping forever', function () {
    Http::fake([
        'https://x.test/broken' => Http::response('', 500),
        '*' => Http::response(json_encode(['items' => [['sku' => 'X']]]), 200),
    ]);

    $user = User::factory()->create();
    $graph = new GraphService;

    $broken = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Broken',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/broken', 'format' => 'json']],
    ]);
    $healthy = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Healthy',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    $processed = makeRecalculator()->drainReadyNodes(realExecutionContext());

    expect($processed)->toBe(1)
        ->and($broken->fresh()->status)->toBe('stale')
        ->and($healthy->fresh()->status)->toBe('fresh');
});

it('claimAndProcess puts a failing node back to stale and reports the failure', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/broken', 'format' => 'json']],
    ]);

    $result = makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($result->node->id)->toBe($node->id)
        ->and($result->failure)->toBeInstanceOf(PipelineException::class)
        ->and($node->fresh()->status)->toBe('stale');
});

it('claimAndProcess returns nothingReady() when no node is ready', function () {
    $result = makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeTrue()
        ->and($result->node)->toBeNull();
});

it('records this run\'s tokens_spent in run_stats, not a cumulative total across nodes', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'text' => 'great product']],
    ]), 200)]);

    $user = User::factory()->create();
    $fetchAndExtract = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];
    // Different instructions, so B can't hit A's cached answer.
    $nodeA = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [
        ...$fetchAndExtract,
        ['op' => 'llm', 'instruction' => 'Classify sentiment (A)', 'input_fields' => ['text'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
    ]]);
    $nodeB = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'B', 'dsl_config' => [
        ...$fetchAndExtract,
        ['op' => 'llm', 'instruction' => 'Classify sentiment (B)', 'input_fields' => ['text'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
    ]]);

    $recalculator = makeRecalculator();
    // One context for both runs, as in the worker.
    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider(tokensPerCall: 42), new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore);

    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);

    $statA = RunStat::query()->where('node_id', $nodeA->id)->where('status', 'success')->sole();
    $statB = RunStat::query()->where('node_id', $nodeB->id)->where('status', 'success')->sole();

    expect($statA->tokens_spent)->toBe(42)
        ->and($statB->tokens_spent)->toBe(42);
});

it('claimAndProcess sends an exceeded UsageLimiter check straight to failed, not stale', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'rows_per_node' => 0]);

    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([['row_key' => 'r1', 'data' => ['x' => 'v']]]));

    // With rows_per_node = 0 any input exceeds the limit.
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['x'], 'output_field' => 'y', 'model_tier' => 'standard'],
        ],
    ], [$parent->id]);

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new GraphUsageLimiter, new GraphRowStore);

    $result = makeRecalculator()->claimAndProcess($ctx);

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('failed')
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'failed')->exists())->toBeTrue();
});

it('drainReadyNodes also fails a node immediately on an exceeded UsageLimiter check', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'rows_per_node' => 0]);

    $parent = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([['row_key' => 'r1', 'data' => ['x' => 'v']]]));

    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['x'], 'output_field' => 'y', 'model_tier' => 'standard'],
        ],
    ], [$parent->id]);

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new GraphUsageLimiter, new GraphRowStore);

    $processed = makeRecalculator()->drainReadyNodes($ctx);

    expect($processed)->toBe(0)
        ->and($node->fresh()->status)->toBe('failed');
});

it('processNode records a data_changed notification when content actually changes', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'price' => 10]],
    ]), 200)]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    makeRecalculator()->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();

    expect($notification->type)->toBe('data_changed')
        ->and($notification->payload)->toBe(['added' => 1, 'changed' => 0, 'removed' => 0]);
});

it('processNode does not record a notification when content is unchanged', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $user = User::factory()->create();
    $dsl = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $dsl,
    ]);
    $ctx = realExecutionContext();

    $recalculator = makeRecalculator();
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);

    $node->fresh()->update(['status' => 'stale']);
    $recalculator->processNode((new SchedulerService)->claimReadyNode(), $ctx);

    expect(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(1);
});

it('marks a node stale (not fresh) if a dependency\'s version changed while it was running', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['x' => 1]],
    ]));

    $child = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child',
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ], [$parent->id]);

    // The parent gets a new version after the child has read it but before the child commits.
    $racingPipelineRunner = new class extends PipelineRunner
    {
        public ?Node $dependencyToRace = null;

        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            $payload = parent::run($nodeId, $dslConfig, $ctx, $runId);

            if ($this->dependencyToRace !== null) {
                app(ResultStore::class)->store($this->dependencyToRace, new RowSet([
                    ['row_key' => 'r1', 'data' => ['x' => 2]],
                ]));
            }

            return $payload;
        }
    };
    $racingPipelineRunner->dependencyToRace = $parent;

    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $topology->declare($channel);

    $recalculator = new NodeRecalculator(
        new SchedulerService,
        new ResultStore,
        $racingPipelineRunner,
        new NodeEventPublisher($channel),
    );

    $claimedChild = (new SchedulerService)->claimReadyNode();
    expect($claimedChild->id)->toBe($child->id);

    $recalculator->processNode($claimedChild, realExecutionContext());

    expect($child->fresh()->status)->toBe('stale');
});

it('does not touch status when no dependency changed during the run', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    app(ResultStore::class)->store($parent, new RowSet([
        ['row_key' => 'r1', 'data' => ['x' => 1]],
    ]));

    $child = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child',
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ], [$parent->id]);

    $claimedChild = (new SchedulerService)->claimReadyNode();
    makeRecalculator()->processNode($claimedChild, realExecutionContext());

    expect($child->fresh()->status)->toBe('fresh');
});

it('markFailed records a failed run_stats row and moves the node to failed', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'A',
        'dsl_config' => [],
    ]);

    $exception = new PipelineException(0, 'merge', new RuntimeException('boom'));

    makeRecalculator()->markFailed($node, $exception);

    expect($node->fresh()->status)->toBe('failed')
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'failed')->exists())->toBeTrue();

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();
    expect($notification->type)->toBe('failed')
        ->and($notification->payload)->toBe(['message' => 'Шаг 0 (merge): '.PublicErrorMessage::GENERIC]);
});

it('claimAndProcess moves a node with drifted source data to schema_drift, not stale', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $user = User::factory()->create();
    // The schema declares `price`, which the data lacks.
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
        'columns_schema' => [
            'sku' => ['name' => 'sku', 'type' => 'string'],
            'price' => ['name' => 'price', 'type' => 'number'],
        ],
    ]);

    $result = makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('schema_drift')
        ->and($node->fresh()->current_version_id)->toBeNull() // halted before ResultStore ever ran
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'schema_drift')->exists())->toBeTrue();

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();
    expect($notification->type)->toBe('schema_drift')
        ->and($notification->payload['missing_fields'])->toBe(['price' => 'price']);
});

it('claimAndProcess sends a run that exceeds its wall-clock budget straight to failed, not stale', function () {
    Http::fake(['*' => Http::response(json_encode([
        'items' => [['sku' => 'A1', 'text' => 'great product']],
    ]), 200)]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
            ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['text'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
        ],
    ]);

    // LlmOperation calls $ctx->heartbeat() after its one chunk; a budget of
    // -1s has already elapsed by the time heartbeat() is first called.
    $result = makeRecalculator(maxRunSeconds: -1)->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('failed')
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'failed')->exists())->toBeTrue()
        ->and(NodeNotification::query()->where('node_id', $node->id)->where('type', 'failed')->exists())->toBeTrue();
});

it('does not touch a schema-drifted node\'s children (only a new version marks them)', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1']]]), 200)]);

    $user = User::factory()->create();
    $graph = new GraphService;

    $drifted = $graph->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);
    $child = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'B',
        'dsl_config' => [['op' => 'parent', 'node' => $drifted->id]],
    ], [$drifted->id]);
    $child->update(['status' => 'fresh']);

    makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($child->fresh()->status)->toBe('fresh');
});

// Steps are chained in memory; rows are written once per run.
it('writes exactly as many node_rows as the final row count, not multiplied by the number of chain steps', function () {
    Http::fake(['*' => Http::response(json_encode(['items' => [
        ['sku' => 'A1', 'price' => 10, 'text' => 'great'],
        ['sku' => 'A2', 'price' => 3, 'text' => 'meh'],
        ['sku' => 'A3', 'price' => 7, 'text' => 'good'],
    ]]), 200)]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
            ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
            ['op' => 'map', 'fields' => ['sku' => 'sku', 'price' => 'price', 'text' => 'text']],
            ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['text'], 'output_field' => 'label', 'model_tier' => 'standard'],
        ],
    ]);

    makeRecalculator()->processNode((new SchedulerService)->claimReadyNode(), realExecutionContext());

    // Only the filtered rows are stored.
    expect($node->fresh()->status)->toBe('fresh')
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(2)
        ->and($node->fresh()->currentVersion->rows()->count())->toBe(2);
});

// A failed run's drafts are removed at once; only a multi-batch run can leave any.
it('discards a failed multi-batch run\'s draft rows immediately, not just on the node\'s next attempt', function () {
    Http::fake([
        'https://x.test/items?page=1' => Http::response(json_encode(['items' => [
            ['sku' => 'A1', 'price' => 10],
        ]]), 200),
        // Page 2 lacks "price", so the run fails after page 1 was written.
        'https://x.test/items?page=2' => Http::response(json_encode(['items' => [
            ['sku' => 'A2'],
        ]]), 200),
        'https://x.test/items?page=3' => Http::response(json_encode(['items' => []]), 200),
    ]);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json', 'pagination' => [
                'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10,
            ]],
            ['op' => 'extract', 'path' => '$[*]', 'row_key_field' => 'sku'],
            ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
        ],
    ]);

    $result = makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(0);
});

// non-retryable step errors fail the node at once

it('goes straight to failed (not stale) when a step throws a PHP Error, e.g. a bad map formula', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [],
    ]);

    $throwingRunner = new class extends PipelineRunner
    {
        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            throw new PipelineException(0, 'map', new TypeError('Unsupported operand types: string + int'));
        }
    };

    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, $throwingRunner, new NodeEventPublisher(
        tap((new RabbitMqTopology)->connect(), fn ($channel) => (new RabbitMqTopology)->declare($channel))
    ));

    $result = $recalculator->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('failed');
});

it('goes straight to failed (not stale) when a step throws a LogicException, e.g. malformed expression syntax', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [],
    ]);

    $throwingRunner = new class extends PipelineRunner
    {
        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            throw new PipelineException(0, 'map', new LogicException('Malformed expression'));
        }
    };

    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, $throwingRunner, new NodeEventPublisher(
        tap((new RabbitMqTopology)->connect(), fn ($channel) => (new RabbitMqTopology)->declare($channel))
    ));

    $result = $recalculator->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('failed');
});

it('goes straight to failed for any exception from a map step, even a plain RuntimeException', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [],
    ]);

    // `map` can throw a plain RuntimeException, which must not be retried either.
    $throwingRunner = new class extends PipelineRunner
    {
        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            throw new PipelineException(0, 'map', new RuntimeException('Unable to get property "bar" of non-object "foo".'));
        }
    };

    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, $throwingRunner, new NodeEventPublisher(
        tap((new RabbitMqTopology)->connect(), fn ($channel) => (new RabbitMqTopology)->declare($channel))
    ));

    $result = $recalculator->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('failed');
});

it('still retries (stays stale) on a plain transient exception, unaffected by the non-retryable fix', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [],
    ]);

    $throwingRunner = new class extends PipelineRunner
    {
        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            throw new PipelineException(0, 'fetch', new RuntimeException('connection reset'));
        }
    };

    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, $throwingRunner, new NodeEventPublisher(
        tap((new RabbitMqTopology)->connect(), fn ($channel) => (new RabbitMqTopology)->declare($channel))
    ));

    $result = $recalculator->claimAndProcess(realExecutionContext());

    expect($result->succeeded)->toBeFalse()
        ->and($node->fresh()->status)->toBe('stale');
});

// lock lost mid-run

/**
 * Runs the real pipeline, then drops the node's lock — either right after it
 * returns (only the pre-commit check can notice) or followed by a heartbeat
 * from inside the run (surfacing as a PipelineException, like a real step).
 */
function lockLosingRunner(bool $heartbeatInsideRun): PipelineRunner
{
    return new class($heartbeatInsideRun) extends PipelineRunner
    {
        public function __construct(private readonly bool $heartbeatInsideRun)
        {
            parent::__construct();
        }

        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            $payload = parent::run($nodeId, $dslConfig, $ctx, $runId);
            Cache::store('redis')->lock("node:{$nodeId}")->forceRelease();

            if ($this->heartbeatInsideRun) {
                try {
                    $ctx->heartbeat();
                } catch (Throwable $e) {
                    throw new PipelineException(0, 'fetch', $e);
                }
            }

            return $payload;
        }
    };
}

it('abandons the run without a version or a status change when the lock is lost', function (bool $heartbeatInsideRun) {
    Http::fake(['*' => Http::response(json_encode(['items' => [['sku' => 'A1', 'price' => 10]]]), 200)]);
    Log::spy();

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
    ]);

    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, lockLosingRunner($heartbeatInsideRun), new NodeEventPublisher(
        tap((new RabbitMqTopology)->connect(), fn ($channel) => (new RabbitMqTopology)->declare($channel))
    ));

    $result = $recalculator->claimAndProcess(realExecutionContext());

    $after = $node->fresh();
    expect($result->succeeded)->toBeTrue() // ack the message: nothing to retry
        ->and($after->status)->toBe('processing')
        ->and($after->current_version_id)->toBeNull()
        ->and(DB::table('node_versions')->where('node_id', $node->id)->count())->toBe(0)
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(0)
        ->and(RunStat::query()->where('node_id', $node->id)->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'lock lost'))
        ->once();
})->with([
    'noticed by the pre-commit check' => false,
    'noticed by a heartbeat during the run' => true,
]);

// claim token: only the current claim may write

/** Runs the real pipeline, then $during, then throws $failure if given. */
function interruptedRunner(Closure $during, ?Throwable $failure = null): PipelineRunner
{
    return new class($during, $failure) extends PipelineRunner
    {
        public function __construct(private readonly Closure $during, private readonly ?Throwable $failure)
        {
            parent::__construct();
        }

        public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
        {
            $payload = parent::run($nodeId, $dslConfig, $ctx, $runId);
            ($this->during)($nodeId);

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $payload;
        }
    };
}

function recalculatorWith(PipelineRunner $runner): NodeRecalculator
{
    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $topology->declare($channel);

    return new NodeRecalculator(new SchedulerService, new ResultStore, $runner, new NodeEventPublisher($channel));
}

function claimableWebhookNode(): Node
{
    return (new GraphService)->createNode([
        'user_id' => User::factory()->create()->id, 'type' => 'source', 'name' => 'Hook', 'trigger' => 'webhook',
        'dsl_config' => [
            ['op' => 'webhook_source'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
        'webhook_payload' => ['items' => [['sku' => 'old']]],
    ]);
}

function deliverWebhook(string $nodeId, string $sku): void
{
    (new WebhookIngestService)->ingest(Node::find($nodeId), ['items' => [['sku' => $sku]]]);
}

it('stamps a new token on every claim and keeps it out of the node\'s JSON', function () {
    $node = claimableWebhookNode();
    $first = (new SchedulerService)->claimReadyNode();
    (new NodeRunStateService)->release($first);
    $firstToken = $node->fresh()->claim_token;
    $second = (new SchedulerService)->claimReadyNode();

    expect($firstToken)->toBeNull()
        ->and($second->claim_token)->toBeString()
        ->and($second->toArray())->not->toHaveKeys(['claim_token', 'data_revision', 'consecutive_failures']);
});

it('stops a worker whose node was recovered and re-claimed from committing or changing status', function () {
    $node = claimableWebhookNode();
    $first = (new SchedulerService)->claimReadyNode();
    $stale = clone $first;
    $secondToken = null;
    $secondRun = (string) Str::orderedUuid();

    // The first worker's Redis lock stays held, so only the token can stop it.
    $runner = interruptedRunner(function (string $nodeId) use (&$secondToken, $secondRun) {
        (new NodeRunStateService)->release(Node::find($nodeId)); // what recover-stuck-nodes does
        $secondToken = (new SchedulerService)->claimReadyNode()->claim_token;
        (new GraphRowStore)->appendBatch($secondRun, $nodeId, [['row_key' => 'second', 'data' => ['sku' => 'second']]]);
    });

    expect(recalculatorWith($runner)->processNode($first, realExecutionContext()))->toBeFalse();

    $recalculator = makeRecalculator();
    $recalculator->markFailed(clone $stale, new RuntimeException('late'));
    $recalculator->markSchemaDrift(clone $stale, new SchemaDriftException(['sku' => 'sku']));
    $retried = (new NodeRunStateService)->retryLater(clone $stale);

    $after = $node->fresh();
    expect($retried)->toBeNull()
        ->and($after->status)->toBe('processing')
        ->and($after->claim_token)->toBe($secondToken)
        ->and($after->consecutive_failures)->toBe(0)
        ->and(DB::table('node_versions')->where('node_id', $node->id)->count())->toBe(0)
        ->and(DB::table('node_rows')->where('node_id', $node->id)->pluck('run_id')->all())->toBe([$secondRun])
        ->and(RunStat::query()->where('node_id', $node->id)->count())->toBe(0)
        ->and(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(0);
});

it('ResultStore rolls back and throws when the claim moved on', function () {
    $node = claimableWebhookNode();
    $first = (new SchedulerService)->claimReadyNode();
    (new NodeRunStateService)->release(clone $first);
    (new SchedulerService)->claimReadyNode();

    expect(fn () => (new ResultStore)->store($first, new RowSet([['row_key' => 'a', 'data' => ['sku' => 'a']]])))
        ->toThrow(LockLostException::class)
        ->and(DB::table('node_versions')->where('node_id', $node->id)->count())->toBe(0)
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(0);
});

it('cancels the run in progress when the config is applied or rolled back', function (string $action) {
    $graph = new GraphService;
    $node = claimableWebhookNode();
    $originalConfig = $node->dsl_config;
    $originalVersion = NodeConfigVersion::query()->where('node_id', $node->id)->value('id');
    $changed = [$originalConfig[0], ['op' => 'extract', 'path' => '$.items[*]']];

    if ($action === 'rollback') {
        $graph->updateDslConfig($node, $changed);
    }

    $runner = interruptedRunner(function (string $nodeId) use ($graph, $action, $originalVersion, $changed) {
        $action === 'rollback'
            ? $graph->rollbackConfig(Node::find($nodeId), $originalVersion)
            : $graph->updateDslConfig(Node::find($nodeId), $changed);
    });

    recalculatorWith($runner)->claimAndProcess(realExecutionContext());

    $after = $node->fresh();
    expect($after->status)->toBe('stale')
        ->and($after->claim_token)->toBeNull()
        ->and($after->current_version_id)->toBeNull()
        ->and($after->dsl_config)->toBe($action === 'rollback' ? $originalConfig : $changed);
})->with(['apply', 'rollback']);

// webhook payloads arriving during a run

it('keeps the run when a webhook arrives mid-run: commits it, then leaves the node stale', function () {
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);
    $node = claimableWebhookNode();
    $during = [];

    $runner = interruptedRunner(function (string $nodeId) use (&$during) {
        $before = Node::find($nodeId);
        $this->travel(2)->minutes();
        deliverWebhook($nodeId, 'new');
        $during = [$before, Node::find($nodeId)];
    });
    recalculatorWith($runner)->claimAndProcess(realExecutionContext());

    [$before, $mid] = $during;
    expect($mid->status)->toBe('processing')
        ->and($mid->claim_token)->toBe($before->claim_token)
        ->and($mid->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and($mid->data_revision)->toBe($before->data_revision + 1);

    $after = $node->fresh();
    expect($after->status)->toBe('stale')
        ->and($after->current_version_id)->not->toBeNull()
        ->and($after->currentVersion->rows()->pluck('node_rows.row_key')->all())->toBe(['old'])
        ->and(collect($fake->sent)->where('event', 'node.status_changed'))->toBeEmpty();

    makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->currentVersion->rows()->pluck('node_rows.row_key')->all())->toBe(['new']);
});

it('marks an idle node stale and bumps its revision on a webhook', function () {
    $node = claimableWebhookNode();
    $node->update(['status' => 'failed']);

    deliverWebhook($node->id, 'new');

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->data_revision)->toBe(1)
        ->and($node->fresh()->webhook_payload)->toBe(['items' => [['sku' => 'new']]]);
});

it('retries newer data after a failure, but gives up on the third failure in a row', function (Throwable $cause, string $final) {
    $node = claimableWebhookNode();
    $recalculator = recalculatorWith(interruptedRunner(
        fn (string $nodeId) => deliverWebhook($nodeId, 'newer'),
        new PipelineException(1, 'map', $cause),
    ));

    $statuses = [];
    foreach (range(1, 3) as $attempt) {
        $recalculator->claimAndProcess(realExecutionContext());
        $statuses[] = $node->fresh()->status;
    }

    expect($statuses)->toBe(['stale', 'stale', $final])
        ->and($node->fresh()->consecutive_failures)->toBe(3)
        ->and($node->fresh()->claim_token)->toBeNull()
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', $final)->count())->toBe(3)
        ->and(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(1);
})->with([
    'broken formula' => [new TypeError('bad formula'), 'failed'],
    'schema drift' => [new SchemaDriftException(['sku' => 'sku']), 'schema_drift'],
]);

it('writes failed at once when no newer data arrived', function () {
    $node = claimableWebhookNode();

    recalculatorWith(interruptedRunner(fn () => null, new PipelineException(1, 'map', new TypeError('bad'))))
        ->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('failed');
});

it('resets the failure counter after a successful run', function () {
    $node = claimableWebhookNode();
    $node->update(['consecutive_failures' => 2]);

    makeRecalculator()->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->consecutive_failures)->toBe(0);
});

function httpError(int $status): RequestException
{
    return new RequestException(new HttpResponse(new PsrResponse($status)));
}

it('fails at once, without retries, on a permanent error', function (string $op, Closure $cause) {
    $node = claimableWebhookNode();

    recalculatorWith(interruptedRunner(fn () => null, new PipelineException(0, $op, $cause())))
        ->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('failed')
        ->and($node->fresh()->retry_at)->toBeNull()
        ->and($node->fresh()->consecutive_failures)->toBe(1)
        ->and(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(1)
        ->and((new SchedulerService)->claimReadyNode())->toBeNull();
})->with([
    'blocked address' => ['fetch', fn () => new SsrfBlockedException('blocked')],
    'DSL error' => ['filter', fn () => new DslException('bad cmp')],
    'expression error' => ['map', fn () => new ExpressionException('bad formula')],
    'unknown operation' => ['explode', fn () => new UnknownOperationException('explode')],
    'response too large' => ['fetch', fn () => new ResponseTooLargeException('too large')],
    'source 400' => ['fetch', fn () => httpError(400)],
    'source 404' => ['fetch', fn () => httpError(404)],
]);

it('tries a retryable error three times with a growing delay, then fails', function (string $op, Closure $cause) {
    $this->freezeSecond();
    $node = claimableWebhookNode();
    $recalculator = recalculatorWith(interruptedRunner(fn () => null, new PipelineException(0, $op, $cause())));
    $scheduler = new SchedulerService;

    foreach ([1 => 30, 2 => 60] as $attempt => $delay) {
        expect($recalculator->claimAndProcess(realExecutionContext())->node?->id)->toBe($node->id)
            ->and($node->fresh()->status)->toBe('stale')
            ->and($node->fresh()->consecutive_failures)->toBe($attempt)
            ->and((int) now()->diffInSeconds($node->fresh()->retry_at))->toBe($delay)
            ->and(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(0);

        $this->travel($delay - 1)->seconds();
        expect($scheduler->claimReadyNode())->toBeNull();
        $this->travel(1)->seconds();
    }

    $recalculator->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('failed')
        ->and($node->fresh()->retry_at)->toBeNull()
        ->and($node->fresh()->consecutive_failures)->toBe(3)
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'failed')->count())->toBe(1)
        ->and(NodeNotification::query()->where('node_id', $node->id)->count())->toBe(1);
})->with([
    'connection or timeout' => ['fetch', fn () => new ConnectionException('timed out')],
    'source 429' => ['fetch', fn () => httpError(429)],
    'source 503' => ['fetch', fn () => httpError(503)],
    'unresolvable host' => ['fetch', fn () => new HostUnresolvedException('no address')],
    'LLM provider' => ['llm', fn () => new RuntimeException('llm operation: call failed', previous: httpError(400))],
]);

it('drain leaves a delayed node alone instead of retrying it on every sweep', function () {
    $node = claimableWebhookNode();
    $recalculator = recalculatorWith(interruptedRunner(fn () => null, new PipelineException(0, 'fetch', new ConnectionException('down'))));

    foreach (range(1, 4) as $sweep) {
        $recalculator->drainReadyNodes(realExecutionContext());
    }

    expect($node->fresh()->consecutive_failures)->toBe(1)
        ->and($node->fresh()->status)->toBe('stale');
});

it('keeps schema_drift without retries', function () {
    $node = claimableWebhookNode();

    recalculatorWith(interruptedRunner(fn () => null, new PipelineException(1, 'schema_drift', new SchemaDriftException(['sku' => 'sku']))))
        ->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('schema_drift');
});

it('hands the claim back when the Redis lock is busy, without looping in a drain', function () {
    $node = claimableWebhookNode();
    $busy = Cache::store('redis')->lock("node:{$node->id}", 60);
    $busy->get();

    try {
        $processed = makeRecalculator()->drainReadyNodes(realExecutionContext());
    } finally {
        $busy->release();
    }

    expect($processed)->toBe(0)
        ->and($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->claim_token)->toBeNull()
        ->and($node->fresh()->consecutive_failures)->toBe(0);
});

// runs of one node don't delete each other's drafts

it('commits the complete result even if a preview of the node runs in the middle', function () {
    $node = claimableWebhookNode();
    $node->update(['webhook_payload' => ['items' => [['sku' => 'a'], ['sku' => 'b']]]]);

    $runner = interruptedRunner(function (string $nodeId) {
        (new PreviewService(realExecutionContext()))->preview(Node::find($nodeId), Node::find($nodeId)->dsl_config);
    });
    recalculatorWith($runner)->claimAndProcess(realExecutionContext());

    expect($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->currentVersion->rows()->count())->toBe(2);
});

it('removes a dead run\'s old drafts when the worker next processes the node', function () {
    $node = claimableWebhookNode();
    $deadRun = (string) Str::orderedUuid();
    (new GraphRowStore)->appendBatch($deadRun, $node->id, [['row_key' => 'z', 'data' => ['sku' => 'z']]]);
    $this->travel(31)->minutes();

    makeRecalculator()->claimAndProcess(realExecutionContext());

    expect(DB::table('node_rows')->where('run_id', $deadRun)->count())->toBe(0);
});

it('claims nothing more once a stop is requested', function () {
    $first = claimableWebhookNode();
    $second = claimableWebhookNode();
    $asked = 0;

    $processed = makeRecalculator()->drainReadyNodes(realExecutionContext(), function () use (&$asked) {
        return ++$asked > 1;
    });

    expect($processed)->toBe(1)
        ->and(collect([$first->fresh()->status, $second->fresh()->status])->sort()->values()->all())->toBe(['fresh', 'stale']);
});

// database errors during a run

it('retries a node whose commit hits a database error, then fails it', function () {
    // Same row key with different content violates a unique key at commit.
    $node = claimableWebhookNode();
    $node->update(['webhook_payload' => ['items' => [['sku' => 'a', 'n' => 1], ['sku' => 'a', 'n' => 2]]]]);
    $recalculator = makeRecalculator();
    $statuses = [];

    foreach (range(1, 3) as $attempt) {
        $result = $recalculator->claimAndProcess(realExecutionContext());
        expect($result->failure?->getPrevious())->toBeInstanceOf(PDOException::class);
        $statuses[] = $node->fresh()->status;
        $this->travel(2)->minutes();
    }

    expect($statuses)->toBe(['stale', 'stale', 'failed'])
        ->and($node->fresh()->claim_token)->toBeNull()
        ->and($node->fresh()->current_version_id)->toBeNull()
        ->and(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(0)
        ->and(NodeNotification::query()->where('node_id', $node->id)->sole()->payload)
        ->toBe(['message' => 'Шаг 2 (commit): '.PublicErrorMessage::GENERIC]);
});

it('carries on with other nodes after one hits a database error', function () {
    $broken = claimableWebhookNode();
    $broken->update(['webhook_payload' => ['items' => [['sku' => 'a', 'n' => 1], ['sku' => 'a', 'n' => 2]]]]);
    $healthy = claimableWebhookNode();

    makeRecalculator()->drainReadyNodes(realExecutionContext());

    expect($broken->fresh()->status)->toBe('stale')
        ->and($healthy->fresh()->status)->toBe('fresh');
});
