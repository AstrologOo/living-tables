<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeUsageLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));
    $channel->getConnection()->close();
});

function postWebhook(string $nodeId, string $secretInUrl, string $body, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_X-Webhook-Signature'] = $signature;
    }

    return test()->call('POST', "/webhooks/{$nodeId}/{$secretInUrl}", server: $server, content: $body);
}

it('accepts a correctly-signed request: stores the payload and marks the node stale', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [['op' => 'webhook_source'], ['op' => 'extract', 'path' => '$.items[*]']],
        'trigger' => 'webhook',
        'webhook_secret' => 'shh',
    ]);
    $node->update(['status' => 'fresh']);

    $body = '{"items":[{"sku":"A1"}]}';
    $signature = hash_hmac('sha256', $body, 'shh');

    postWebhook($node->id, 'shh', $body, $signature)->assertNoContent();

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->webhook_payload)->toBe(['items' => [['sku' => 'A1']]]);
});

it('rejects a wrong signature with 403 and does not touch the node', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'trigger' => 'webhook', 'webhook_secret' => 'shh',
    ]);
    $node->update(['status' => 'fresh']);

    postWebhook($node->id, 'shh', '{"items":[]}', 'totally-wrong')->assertForbidden();

    expect($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->webhook_payload)->toBeNull();
});

it('rejects a request with no signature header at all', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'trigger' => 'webhook', 'webhook_secret' => 'shh',
    ]);

    postWebhook($node->id, 'shh', '{"items":[]}', null)->assertForbidden();
});

it('returns 404 when the URL secret does not match the node\'s', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'trigger' => 'webhook', 'webhook_secret' => 'shh',
    ]);

    $body = '{"items":[]}';
    $signature = hash_hmac('sha256', $body, 'shh');

    postWebhook($node->id, 'wrong-secret-in-url', $body, $signature)->assertNotFound();
});

it('returns 404 for a node that is not a webhook source', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        // a polled source, without trigger or secret
    ]);

    postWebhook($node->id, 'anything', '{}', 'anything')->assertNotFound();
});

it('returns 404 for a nonexistent node id', function () {
    postWebhook((string) Str::orderedUuid(), 'shh', '{}', 'sig')->assertNotFound();
});

it('end-to-end: a real ingested webhook payload becomes real rows on the next recompute', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [
            ['op' => 'webhook_source'],
            ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ],
        'trigger' => 'webhook',
        'webhook_secret' => 'shh',
    ]);

    $body = json_encode(['items' => [['sku' => 'A1', 'price' => 10]]]);
    $signature = hash_hmac('sha256', $body, 'shh');
    postWebhook($node->id, 'shh', $body, $signature)->assertNoContent();

    expect($node->fresh()->status)->toBe('stale');

    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $topology->declare($channel);
    $recalculator = new NodeRecalculator(
        new SchedulerService,
        new ResultStore,
        new PipelineRunner,
        new NodeEventPublisher($channel),
    );
    $ctx = new ExecutionContext(
        new GraphParentDataProvider,
        new FakeLlmProvider,
        new FakeDocumentParser,
        new FakeLlmCacheRepository,
        new FakeUsageLimiter,
        new GraphRowStore,
    );

    $claimed = (new SchedulerService)->claimReadyNode();
    expect($claimed->id)->toBe($node->id);

    $recalculator->processNode($claimed, $ctx);

    $fresh = $node->fresh();
    expect($fresh->status)->toBe('fresh')
        ->and($fresh->currentVersion->rows()->count())->toBe(1)
        ->and($fresh->currentVersion->rows()->first()->data)->toBe(['sku' => 'A1', 'price' => 10]);
});

it('answers a refused webhook with JSON, whatever the sender accepts', function (string $url, int $status) {
    $response = $this->call('POST', $url, content: '{}');

    $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
    expect($response->json('message'))->toBeString();
})->with([
    'unknown node' => ['/webhooks/00000000-0000-7000-8000-000000000000/secret', 404],
    'no such route' => ['/webhooks/only-a-node', 404],
    'a node id that is not a UUID' => ['/webhooks/not-a-uuid/secret', 404],
]);

it('answers a bad signature with JSON', function () {
    $node = (new GraphService)->createNode([
        'user_id' => User::factory()->create()->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'trigger' => 'webhook', 'webhook_secret' => 'secret',
    ]);

    $this->call('POST', "/webhooks/{$node->id}/secret", content: '{}')
        ->assertStatus(403)->assertHeader('Content-Type', 'application/json');
});
