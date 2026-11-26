<?php

use App\Models\User;
use App\Modules\Graph\Exceptions\CycleDetectedException;
use App\Modules\Graph\Models\Edge;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a node with a config_hash derived from dsl_config', function () {
    $user = User::factory()->create();

    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://example.com']],
    ]);

    expect($node)->toBeInstanceOf(Node::class)
        ->and($node->exists)->toBeTrue()
        ->and($node->config_hash)->toHaveLength(64);
});

it('creates edges to each given parent', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $parent = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
    ]);

    $child = $service->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'B',
        'dsl_config' => [['op' => 'parent', 'node' => $parent->id]],
    ], [$parent->id]);

    expect(Edge::query()->where('node_id', $child->id)->where('depends_on_id', $parent->id)->exists())->toBeTrue()
        ->and($child->parents()->pluck('nodes.id'))->toEqual(collect([$parent->id]));
});

it('produces the same config_hash regardless of key order', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $a = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => ['url' => 'https://example.com', 'format' => 'json'],
    ]);

    $b = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'B',
        'dsl_config' => ['format' => 'json', 'url' => 'https://example.com'],
    ]);

    expect($a->config_hash)->toBe($b->config_hash);
});

it('produces a different config_hash when list order changes', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $a = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [['op' => 'fetch'], ['op' => 'filter']],
    ]);

    $b = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'B',
        'dsl_config' => [['op' => 'filter'], ['op' => 'fetch']],
    ]);

    expect($a->config_hash)->not->toBe($b->config_hash);
});

it('rejects an edge that would create a cycle', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $create = fn (string $name) => $service->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => $name,
        'dsl_config' => [],
    ]);

    $a = $create('A');
    $b = $create('B');
    $c = $create('C');

    // A -> B -> C (B depends on A, C depends on B)
    $service->addEdge($b->id, $a->id);
    $service->addEdge($c->id, $b->id);

    expect(fn () => $service->addEdge($a->id, $c->id))
        ->toThrow(CycleDetectedException::class);

    expect(Edge::query()->where('node_id', $a->id)->where('depends_on_id', $c->id)->exists())->toBeFalse();
});

it('rejects a node depending on itself', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'A',
        'dsl_config' => [],
    ]);

    expect(fn () => $service->addEdge($node->id, $node->id))
        ->toThrow(CycleDetectedException::class);
});

it('updateDslConfig is a no-op when the canonical config is unchanged', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => ['url' => 'https://x.test', 'format' => 'json'],
    ]);
    $node->update(['status' => 'fresh']);
    $originalHash = $node->config_hash;

    // Same config with a different key order.
    $changed = $service->updateDslConfig($node, ['format' => 'json', 'url' => 'https://x.test']);

    expect($changed)->toBeFalse()
        ->and($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->config_hash)->toBe($originalHash);
});

it('updateDslConfig marks the node stale and updates config_hash when the config actually changes', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => ['url' => 'https://x.test/a'],
    ]);
    $node->update(['status' => 'fresh']);
    $originalHash = $node->config_hash;

    $changed = $service->updateDslConfig($node, ['url' => 'https://x.test/b']);

    expect($changed)->toBeTrue()
        ->and($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->config_hash)->not->toBe($originalHash)
        ->and($node->fresh()->dsl_config)->toBe(['url' => 'https://x.test/b']);
});

it('updateDslConfig publishes a recalc task when a publisher is given and the config changed', function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));

    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => ['url' => 'https://x.test/a'],
    ]);

    $service->updateDslConfig($node, ['url' => 'https://x.test/b'], new NodeEventPublisher($channel));

    usleep(100_000);
    $message = null;
    for ($i = 0; $i < 20 && $message === null; $i++) {
        $message = $channel->basic_get(config('rabbitmq.queue'));
        if ($message === null) {
            usleep(50_000);
        }
    }

    expect($message)->not->toBeNull()
        ->and(json_decode($message->getBody(), true))->toBe(['node_id' => $node->id]);

    $channel->getConnection()->close();
});

it('updateDslConfig does not publish anything when the config is unchanged', function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));

    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => ['url' => 'https://x.test/a'],
    ]);

    $service->updateDslConfig($node, ['url' => 'https://x.test/a'], new NodeEventPublisher($channel));

    usleep(200_000);
    expect($channel->basic_get(config('rabbitmq.queue')))->toBeNull();

    $channel->getConnection()->close();
});

// affectedNodeIds()

it('affectedNodeIds returns every transitive descendant of a chain', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $a = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $b = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'B', 'dsl_config' => []], [$a->id]);
    $c = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'C', 'dsl_config' => []], [$b->id]);

    expect($service->affectedNodeIds($a->id))->toEqualCanonicalizing([$b->id, $c->id]);
});

it('affectedNodeIds counts a diamond-shaped descendant once, not per path', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $a = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $b = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'B', 'dsl_config' => []], [$a->id]);
    $c = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'C', 'dsl_config' => []], [$a->id]);
    $d = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'D', 'dsl_config' => []], [$b->id, $c->id]);

    $affected = $service->affectedNodeIds($a->id);

    expect($affected)->toEqualCanonicalizing([$b->id, $c->id, $d->id])
        ->and(count($affected))->toBe(3); // D is reached through B and C but counted once.
});

it('affectedNodeIds returns an empty array for a leaf node', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $leaf = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);

    expect($service->affectedNodeIds($leaf->id))->toBe([]);
});

it('affectedNodeIds does not include unrelated nodes or the node itself', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $a = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'Unrelated', 'dsl_config' => []]);

    expect($service->affectedNodeIds($a->id))->toBe([]);
});

// config history and rollback

it('createNode records the initial config as the first history entry', function () {
    $user = User::factory()->create();
    $config = [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']];

    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $config,
    ]);

    $versions = NodeConfigVersion::query()->where('node_id', $node->id)->get();

    expect($versions)->toHaveCount(1)
        ->and($versions->first()->dsl_config)->toBe($config)
        ->and($versions->first()->config_hash)->toBe($node->config_hash);
});

it('updateDslConfig appends a new history entry only when the config actually changes', function () {
    $user = User::factory()->create();
    $service = new GraphService;
    $configA = [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']];
    $configB = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

    $node = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $configA,
    ]);

    $service->updateDslConfig($node, $configA); // no-op, same config
    $service->updateDslConfig($node, $configB); // real change

    $versions = NodeConfigVersion::query()->where('node_id', $node->id)->orderBy('created_at')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->dsl_config)->toBe($configA)
        ->and($versions[1]->dsl_config)->toBe($configB);
});

it('rollbackConfig restores a past dsl_config and appends it as a new history entry', function () {
    $user = User::factory()->create();
    $service = new GraphService;
    $configA = [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']];
    $configB = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

    $node = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => $configA,
    ]);
    $firstVersion = NodeConfigVersion::query()->where('node_id', $node->id)->sole();

    $service->updateDslConfig($node, $configB);
    $node->update(['status' => 'fresh']);

    $changed = $service->rollbackConfig($node, $firstVersion->id);

    expect($changed)->toBeTrue()
        ->and($node->fresh()->dsl_config)->toBe($configA)
        ->and($node->fresh()->status)->toBe('stale')
        ->and(NodeConfigVersion::query()->where('node_id', $node->id)->count())->toBe(3); // A, B, back-to-A
});

it('rollbackConfig throws for a version id that does not belong to the node', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $nodeA = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $nodeB = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'B', 'dsl_config' => []]);
    $versionOfB = NodeConfigVersion::query()->where('node_id', $nodeB->id)->sole();

    expect(fn () => $service->rollbackConfig($nodeA, $versionOfB->id))
        ->toThrow(InvalidArgumentException::class);
});

// default view from a pipeline's trailing steps

it('createNode splits a trailing sort/unique/limit run into a default view, saving only the executable steps', function () {
    $user = User::factory()->create();

    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
            ['op' => 'sort', 'field' => 'price', 'order' => 'desc'],
            ['op' => 'unique', 'field' => 'sku'],
            ['op' => 'limit', 'count' => 5],
        ],
    ]);

    expect($node->dsl_config)->toBe([
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
    ]);

    $view = NodeView::query()->where('node_id', $node->id)->where('is_default', true)->sole();

    expect($view->sort_field)->toBe('price')
        ->and($view->sort_order)->toBe('desc')
        ->and($view->unique_field)->toBe('sku')
        ->and($view->limit_count)->toBe(5)
        ->and($view->user_id)->toBe($user->id);
});

it('createNode does not create a default view when dsl_config has no trailing criteria', function () {
    $user = User::factory()->create();

    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']],
    ]);

    expect(NodeView::query()->where('node_id', $node->id)->exists())->toBeFalse();
});

it('updateDslConfig replaces the default view (not a second one) when re-applied with new criteria', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
            ['op' => 'limit', 'count' => 5],
        ],
    ]);

    $service->updateDslConfig($node, [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'limit', 'count' => 50],
    ]);

    $views = NodeView::query()->where('node_id', $node->id)->where('is_default', true)->get();

    expect($views)->toHaveCount(1)
        ->and($views->first()->limit_count)->toBe(50);
});

it('updateDslConfig deletes the default view once the new dsl_config has no trailing criteria', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [
            ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
            ['op' => 'limit', 'count' => 5],
        ],
    ]);

    $service->updateDslConfig($node, [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']]);

    expect(NodeView::query()->where('node_id', $node->id)->exists())->toBeFalse();
});

it('never touches a user\'s own hand-created views when syncing the default one', function () {
    $user = User::factory()->create();
    $service = new GraphService;

    $node = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']],
    ]);
    $ownView = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'My saved view']);

    $service->updateDslConfig($node, [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'limit', 'count' => 5],
    ]);
    $service->updateDslConfig($node, [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json']]);

    expect(NodeView::find($ownView->id))->not->toBeNull();
});
