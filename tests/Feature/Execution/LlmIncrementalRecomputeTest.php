<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Models\LlmRowCache;
use App\Modules\Graph\Providers\GraphLlmCacheRepository;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\RecordingLlmProvider;

uses(RefreshDatabase::class);

/**
 * A recompute with a few changed rows sends only those to the model; uses the real database cache.
 */
it('only sends changed rows to the LLM on a second recompute, not the whole node again', function () {
    $totalRows = 50;
    $changedRows = 5;

    $user = User::factory()->create();
    $graph = new GraphService;

    $parent = $graph->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => []]);

    $makeRows = fn (string $suffix = '') => array_map(
        fn (int $i) => ['row_key' => "r{$i}", 'data' => ['text' => "review {$i}{$suffix}"]],
        range(1, $totalRows),
    );

    app(ResultStore::class)->store($parent, new RowSet($makeRows()));

    $node = $graph->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'Sentiment',
        'dsl_config' => [
            ['op' => 'parent', 'node' => $parent->id],
            ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['text'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
        ],
    ], [$parent->id]);

    $llm = new RecordingLlmProvider([
        array_map(fn (int $i) => ['id' => $i, 'value' => 'neutral'], range(0, $totalRows - 1)),
        array_map(fn (int $i) => ['id' => $i, 'value' => 'neutral'], range(0, $changedRows - 1)),
    ]);

    $ctx = new ExecutionContext(
        new GraphParentDataProvider,
        $llm,
        new FakeDocumentParser,
        new GraphLlmCacheRepository,
        new GraphUsageLimiter,
        new GraphRowStore,
    );

    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $topology->declare($channel);
    $recalculator = new NodeRecalculator(
        new SchedulerService,
        new ResultStore,
        new PipelineRunner,
        new NodeEventPublisher($channel),
    );

    // Empty cache: one call with every row.
    $recalculator->processNode($node->fresh(), $ctx);

    expect($llm->callCount())->toBe(1)
        ->and(substr_count($llm->lastUserInput(), 'id='))->toBe($totalRows)
        ->and(LlmRowCache::query()->count())->toBe($totalRows);

    // Change 5 rows and recompute.
    app(ResultStore::class)->store($parent, new RowSet([
        ...array_slice($makeRows(), 0, $totalRows - $changedRows),
        ...array_slice($makeRows(' (edited)'), $totalRows - $changedRows),
    ]));
    $node->fresh()->update(['status' => 'stale']);

    $recalculator->processNode($node->fresh(), $ctx);

    expect($llm->callCount())->toBe(2)
        ->and(substr_count($llm->lastUserInput(), 'id='))->toBe($changedRows)
        ->and(LlmRowCache::query()->count())->toBe($totalRows + $changedRows);
});
