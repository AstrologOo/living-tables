<?php

use App\Models\User;
use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeUsageLimiter;

uses(RefreshDatabase::class);

/**
 * Every batch of a run merges with the same version, even if the right-hand node changes mid-run.
 */
it('pins the right-hand node\'s version at first use, so a mid-run update never reaches a later batch\'s merge', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $prices = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Prices', 'dsl_config' => [],
    ]);
    (new ResultStore)->store($prices, new RowSet([
        ['row_key' => 'p1', 'data' => ['sku' => 'A', 'price' => 10]],
        ['row_key' => 'p2', 'data' => ['sku' => 'B', 'price' => 20]],
    ]));

    $items = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Items', 'dsl_config' => [],
    ], [$prices->id]);

    // Distinct pages, so each page's merge result is visible.
    Http::fake([
        'https://x.test/items?page=1' => Http::response(json_encode(['items' => [['sku' => 'A']]]), 200),
        'https://x.test/items?page=2' => Http::response(json_encode(['items' => [['sku' => 'B']]]), 200),
        'https://x.test/items?page=3' => Http::response(json_encode(['items' => []]), 200),
    ]);

    $dsl = [
        ['op' => 'fetch', 'url' => 'https://x.test/items', 'format' => 'json', 'pagination' => [
            'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10,
        ]],
        ['op' => 'extract', 'path' => '$[*]', 'row_key_field' => 'sku'],
        ['op' => 'merge', 'with' => $prices->id, 'on' => 'sku'],
    ];

    $ctx = new ExecutionContext(new GraphParentDataProvider, new FakeLlmProvider, new FakeDocumentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new GraphRowStore);

    // Change the prices on the second heartbeat: after page 1's merge, before page 2's.
    $reporter = new class($prices) implements ProgressReporter
    {
        private int $calls = 0;

        public function __construct(private readonly Node $prices) {}

        public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
        {
            $this->calls++;

            if ($this->calls === 2) {
                app(ResultStore::class)->store($this->prices->fresh(), new RowSet([
                    ['row_key' => 'p1', 'data' => ['sku' => 'A', 'price' => 999]],
                    ['row_key' => 'p2', 'data' => ['sku' => 'B', 'price' => 999]],
                ]));
            }
        }
    };
    $ctx->setProgressReporter($reporter);

    $result = (new PipelineRunner)->run($items->id, $dsl, $ctx);
    $rows = $ctx->rows()->draftRows($result->runId(), $items->id);

    expect(array_column($rows, 'data'))->toEqualCanonicalizing([
        ['sku' => 'A', 'price' => 10],
        ['sku' => 'B', 'price' => 20],
    ]);
});
