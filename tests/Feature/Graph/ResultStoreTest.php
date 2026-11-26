<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Models\NodeVersion;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

$makeNode = fn (GraphService $graph, User $user): Node => $graph->createNode([
    'user_id' => $user->id,
    'type' => 'source',
    'name' => 'A',
    'dsl_config' => [],
]);

/**
 * Writes rows as a run's drafts, as the pipeline would.
 *
 * @param  array<int, array{row_key: string, data: array<string, mixed>}>  $rows
 */
function seedDraftRun(Node $node, array $rows): RowSet
{
    $runId = (string) Str::uuid();
    $store = new GraphRowStore;
    $store->appendBatch($runId, $node->id, $rows);

    return RowSet::fromRun($runId, $node->id, $store->count($runId, $node->id));
}

it('stores rows and creates one version on first write', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $result = $store->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]));
    $version = $result->version;

    expect($version)->toBeInstanceOf(NodeVersion::class)
        ->and(NodeVersion::query()->count())->toBe(1)
        ->and(NodeRow::query()->count())->toBe(3)
        ->and($version->rows()->count())->toBe(3)
        ->and($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->current_version_id)->toBe($version->id)
        ->and($node->fresh()->content_hash)->toBe($version->content_hash);

    // The first version: every row is added.
    expect($result->diff->added)->toEqualCanonicalizing(['1', '2', '3'])
        ->and($result->diff->changed)->toBe([])
        ->and($result->diff->removed)->toBe([]);
});

it('reuses unchanged rows via copy-on-write when content changes', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $v1 = $store->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]))->version;

    $result2 = $store->store($node->fresh(), new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 999]], // changed
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]));
    $v2 = $result2->version;

    expect($v2)->toBeInstanceOf(NodeVersion::class)
        ->and($v2->id)->not->toBe($v1->id)
        ->and(NodeVersion::query()->count())->toBe(2)
        ->and(NodeRow::query()->count())->toBe(4) // 3 original + 1 new (one changed row)
        ->and($v2->rows()->count())->toBe(3)
        ->and($node->fresh()->current_version_id)->toBe($v2->id);

    // two of v2's three row links point at rows already used by v1 (copy-on-write)
    $v1RowIds = $v1->rows()->pluck('node_rows.id');
    $v2RowIds = $v2->rows()->pluck('node_rows.id');
    expect($v1RowIds->intersect($v2RowIds))->toHaveCount(2);

    expect($result2->diff->added)->toBe([])
        ->and($result2->diff->changed)->toBe(['2'])
        ->and($result2->diff->removed)->toBe([]);
});

it('diffs added and removed rows correctly', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $store->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
    ]));

    $result = $store->store($node->fresh(), new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]], // unchanged
        ['row_key' => '3', 'data' => ['x' => 3]], // added
        // '2' is gone -> removed
    ]));

    expect($result->diff->added)->toBe(['3'])
        ->and($result->diff->changed)->toBe([])
        ->and($result->diff->removed)->toBe(['2']);
});

it('returns a null version and an empty diff when content is unchanged', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $rows = fn () => new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]);

    $store->store($node, $rows());
    $result = $store->store($node->fresh(), $rows());

    expect($result->version)->toBeNull()
        ->and($result->diff->count())->toBe(0)
        ->and(NodeVersion::query()->count())->toBe(1)
        ->and($node->fresh()->status)->toBe('fresh');
});

// identical rows under different keys share one stored row

it('lets two different row_keys with identical content share one node_rows record', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $result = $store->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 1]], // byte-identical to row 1
    ]));

    expect(NodeRow::query()->count())->toBe(1) // collapsed by content
        ->and($result->version->rows()->count())->toBe(2) // both row_keys still linked
        ->and($result->diff->added)->toEqualCanonicalizing(['1', '2']);
});

// results stored from a run's drafts

it('run-based: stores rows and creates one version on first write', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $result = $store->store($node, seedDraftRun($node, [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]));
    $version = $result->version;

    expect($version)->toBeInstanceOf(NodeVersion::class)
        ->and(NodeVersion::query()->count())->toBe(1)
        ->and(NodeRow::query()->count())->toBe(3)
        ->and($version->rows()->count())->toBe(3)
        ->and($node->fresh()->status)->toBe('fresh')
        ->and($node->fresh()->current_version_id)->toBe($version->id)
        ->and($node->fresh()->content_hash)->toBe($version->content_hash);

    expect($result->diff->added)->toEqualCanonicalizing(['1', '2', '3'])
        ->and($result->diff->changed)->toBe([])
        ->and($result->diff->removed)->toBe([]);
});

it('run-based: reuses unchanged rows via copy-on-write when content changes, deleting the redundant draft', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $v1 = $store->store($node, seedDraftRun($node, [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]))->version;

    $result2 = $store->store($node->fresh(), seedDraftRun($node->fresh(), [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 999]], // changed
        ['row_key' => '3', 'data' => ['x' => 3]],
    ]));
    $v2 = $result2->version;

    expect($v2)->toBeInstanceOf(NodeVersion::class)
        ->and($v2->id)->not->toBe($v1->id)
        ->and(NodeVersion::query()->count())->toBe(2)
        ->and(NodeRow::query()->count())->toBe(4) // 3 original + 1 new (one changed row); redundant draft deleted
        ->and($v2->rows()->count())->toBe(3)
        ->and($node->fresh()->current_version_id)->toBe($v2->id);

    $v1RowIds = $v1->rows()->pluck('node_rows.id');
    $v2RowIds = $v2->rows()->pluck('node_rows.id');
    expect($v1RowIds->intersect($v2RowIds))->toHaveCount(2);

    expect($result2->diff->added)->toBe([])
        ->and($result2->diff->changed)->toBe(['2'])
        ->and($result2->diff->removed)->toBe([]);
});

it('run-based: diffs added and removed rows correctly', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $store->store($node, seedDraftRun($node, [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
    ]));

    $result = $store->store($node->fresh(), seedDraftRun($node->fresh(), [
        ['row_key' => '1', 'data' => ['x' => 1]], // unchanged
        ['row_key' => '3', 'data' => ['x' => 3]], // added
        // '2' is gone -> removed
    ]));

    expect($result->diff->added)->toBe(['3'])
        ->and($result->diff->changed)->toBe([])
        ->and($result->diff->removed)->toBe(['2']);
});

it('run-based: returns a null version and an empty diff when content is unchanged', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $rows = [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
        ['row_key' => '3', 'data' => ['x' => 3]],
    ];

    $store->store($node, seedDraftRun($node, $rows));
    $result = $store->store($node->fresh(), seedDraftRun($node->fresh(), $rows));

    expect($result->version)->toBeNull()
        ->and($result->diff->count())->toBe(0)
        ->and(NodeVersion::query()->count())->toBe(1)
        ->and($node->fresh()->status)->toBe('fresh');
});

it('run-based: an empty run against a node with prior content removes everything', function () use ($makeNode) {
    $user = User::factory()->create();
    $node = $makeNode(new GraphService, $user);
    $store = new ResultStore;

    $store->store($node, seedDraftRun($node, [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
    ]));

    $result = $store->store($node->fresh(), seedDraftRun($node->fresh(), []));

    expect($result->version)->not->toBeNull()
        ->and($result->version->rows()->count())->toBe(0)
        ->and($result->diff->added)->toBe([])
        ->and($result->diff->removed)->toEqualCanonicalizing(['1', '2']);
});
