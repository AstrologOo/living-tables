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
use Illuminate\Support\Facades\Http;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeUsageLimiter;

uses(RefreshDatabase::class);

/**
 * Graph A -> B -> C -> D: a change in A reaches B and C, but C's output is constant, so D is never marked stale.
 */
it('cascades through nodes whose content actually changes, and stops where it does not', function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);

    $channel->queue_purge(config('rabbitmq.queue'));

    $eventPublisher = new NodeEventPublisher($channel);
    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, new PipelineRunner, $eventPublisher);
    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore);

    $user = User::factory()->create();
    $graph = new GraphService;

    // A answers 1, then 2. A second Http::fake() for the URL would never be reached.
    Http::fake(['*' => Http::sequence()->push(['v' => 1])->push(['v' => 2])]);

    $a = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']],
    ]);
    $b = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'B',
        'dsl_config' => [['op' => 'parent', 'node' => $a->id]],
    ], [$a->id]);
    // C yields the same row whatever B contains.
    $c = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'C',
        'dsl_config' => [
            ['op' => 'parent', 'node' => $b->id],
            ['op' => 'map', 'fields' => ['const' => ['expr' => "'x'"]]],
            ['op' => 'unique', 'field' => 'const'],
        ],
    ], [$b->id]);
    $d = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'D',
        'dsl_config' => [['op' => 'parent', 'node' => $c->id]],
    ], [$c->id]);

    // Compute the whole chain.
    $recalculator->drainReadyNodes($ctx);

    expect(collect([$a, $b, $c, $d])->every(fn ($n) => $n->fresh()->status === 'fresh'))->toBeTrue();

    $cVersionsBefore = $c->fresh()->versions()->count();
    $dVersionsBefore = $d->fresh()->versions()->count();

    $bVersionBefore = $b->fresh()->current_version_id;

    // A's source data changed.
    $a->fresh()->update(['status' => 'stale']);
    $recalculator->claimAndProcess($ctx);

    // Marked by A's commit, without any message.
    expect($b->fresh()->status)->toBe('stale');

    $recalculator->drainReadyNodes($ctx);

    // B mirrors A, so it gets a new version.
    expect($b->fresh()->status)->toBe('fresh')
        ->and($b->fresh()->current_version_id)->not->toBe($bVersionBefore);

    // C ran again with the same result: no new version.
    expect($c->fresh()->versions()->count())->toBe($cVersionsBefore);

    expect($d->fresh()->status)->toBe('fresh')
        ->and($d->fresh()->versions()->count())->toBe($dVersionsBefore);

    $channel->getConnection()->close();
});

it('leaves the children stale for the next sweep when publishing the wake-up fails', function () {
    $channel = (new RabbitMqTopology)->connect();
    $unreachable = new class($channel) extends NodeEventPublisher
    {
        public int $attempts = 0;

        public function publishRecalcTask(string $nodeId): void
        {
            $this->attempts++;

            throw new RuntimeException('broker unavailable');
        }
    };
    $recalculator = new NodeRecalculator(new SchedulerService, new ResultStore, new PipelineRunner, $unreachable);
    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore);
    Http::fake(['*' => Http::sequence()->push(['v' => 1])->push(['v' => 2])]);

    $user = User::factory()->create();
    $graph = new GraphService;
    $a = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']],
    ]);
    $b = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'B',
        'dsl_config' => [['op' => 'parent', 'node' => $a->id]],
    ], [$a->id]);
    $recalculator->drainReadyNodes($ctx);
    $firstVersion = $b->fresh()->current_version_id;

    $a->fresh()->update(['status' => 'stale']);
    $result = $recalculator->claimAndProcess($ctx);

    expect($result->succeeded)->toBeTrue()
        ->and($unreachable->attempts)->toBe(1)
        ->and($a->fresh()->status)->toBe('fresh')
        ->and($b->fresh()->status)->toBe('stale');

    $recalculator->drainReadyNodes($ctx);

    expect($b->fresh()->status)->toBe('fresh')
        ->and($b->fresh()->current_version_id)->not->toBe($firstVersion);

    $channel->getConnection()->close();
});
