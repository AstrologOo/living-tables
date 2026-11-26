<?php

use App\Models\User;
use App\Modules\Execution\Contracts\NodeNotReadableException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rowStoreNode(): Node
{
    $user = User::factory()->create();

    return (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
}

it('appendBatch writes draft rows tagged with the run id', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $runId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [
        ['row_key' => '1', 'data' => ['x' => 1]],
        ['row_key' => '2', 'data' => ['x' => 2]],
    ]);

    $rows = DB::table('node_rows')->where('node_id', $node->id)->where('run_id', $runId)->get();

    expect($rows)->toHaveCount(2)
        ->and($store->count($runId, $node->id))->toBe(2);
});

it('writes a draft row even when identical content already exists as a committed row', function () {
    // Drafts are deduplicated within the run only, so a run that reproduces committed rows still lists them.
    $node = rowStoreNode();
    (new ResultStore)->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
    ]));

    $store = new GraphRowStore;
    $runId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [
        ['row_key' => '1', 'data' => ['x' => 1]], // identical content, already committed
        ['row_key' => '2', 'data' => ['x' => 2]], // genuinely new
    ]);

    expect(DB::table('node_rows')->where('node_id', $node->id)->count())->toBe(3) // 1 committed + 2 drafts
        ->and($store->count($runId, $node->id))->toBe(2)
        ->and($store->draftRows($runId, $node->id))->toBe([
            ['row_key' => '1', 'data' => ['x' => 1]],
            ['row_key' => '2', 'data' => ['x' => 2]],
        ]);
});

it('appendBatch does not duplicate a row across two batches of the same run', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $runId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [['row_key' => '1', 'data' => ['x' => 1]]]);
    $store->appendBatch($runId, $node->id, [['row_key' => '1', 'data' => ['x' => 1]]]);

    expect($store->count($runId, $node->id))->toBe(1);
});

it('discardAbandonedDrafts never deletes a row still referenced by the node\'s own current version', function () {
    // A version may adopt a row that still has a run_id; discarding drafts must not delete it.
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $runId1 = (string) Str::uuid();

    $store->appendBatch($runId1, $node->id, [['row_key' => '1', 'data' => ['x' => 1]]]);
    $result = (new ResultStore)->store($node, new RowSet($store->draftRows($runId1, $node->id)));
    $rowId = DB::table('node_version_rows')->where('version_id', $result->version->id)->value('row_id');

    expect(DB::table('node_rows')->where('id', $rowId)->value('run_id'))->not->toBeNull();

    $this->travel(2)->hours();
    $store->discardAbandonedDrafts($node->id, 1800);

    expect(DB::table('node_rows')->where('id', $rowId)->exists())->toBeTrue();
});

it('discardAbandonedDrafts deletes only drafts older than the longest run, for this node', function () {
    $node = rowStoreNode();
    $other = rowStoreNode();
    $store = new GraphRowStore;
    [$dead, $deadElsewhere, $live] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

    $store->appendBatch($dead, $node->id, [['row_key' => '1', 'data' => ['x' => 1]]]);
    $store->appendBatch($deadElsewhere, $other->id, [['row_key' => '1', 'data' => ['x' => 1]]]);
    $this->travel(31)->minutes();
    $store->appendBatch($live, $node->id, [['row_key' => '2', 'data' => ['x' => 2]]]);

    $store->discardAbandonedDrafts($node->id, 1800);

    expect($store->count($dead, $node->id))->toBe(0)
        ->and($store->count($live, $node->id))->toBe(1)
        ->and($store->count($deadElsewhere, $other->id))->toBe(1);
});

it('availableDataKeys returns the union of keys across this run\'s draft rows', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $runId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [
        ['row_key' => '1', 'data' => ['a' => 1]],
        ['row_key' => '2', 'data' => ['b' => 2]],
    ]);

    expect($store->availableDataKeys($runId, $node->id))->toEqualCanonicalizing(['a', 'b']);
});

it('sampleValues returns the first non-null value found per column', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $runId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [
        ['row_key' => '1', 'data' => ['a' => null, 'b' => 'first']],
        ['row_key' => '2', 'data' => ['a' => 'second', 'b' => 'ignored']],
    ]);

    expect($store->sampleValues($runId, $node->id, ['a', 'b']))->toBe(['b' => 'first', 'a' => 'second']);
});

it('discardRun deletes only this run\'s draft rows, not committed ones or another run\'s', function () {
    $node = rowStoreNode();
    (new ResultStore)->store($node, new RowSet([
        ['row_key' => 'committed', 'data' => ['x' => 'keep']],
    ]));

    $store = new GraphRowStore;
    $runId = (string) Str::uuid();
    $otherRunId = (string) Str::uuid();

    $store->appendBatch($runId, $node->id, [['row_key' => '1', 'data' => ['x' => 'discard-me']]]);
    $store->appendBatch($otherRunId, $node->id, [['row_key' => '2', 'data' => ['x' => 'keep-too']]]);

    $store->discardRun($runId, $node->id);

    expect($store->count($runId, $node->id))->toBe(0)
        ->and($store->count($otherRunId, $node->id))->toBe(1)
        ->and(DB::table('node_rows')->where('node_id', $node->id)->whereNull('run_id')->count())->toBe(1);
});

it('currentVersionId reflects a parent\'s current version, null if it has none', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;
    $reader = (new GraphService)->createNode(['user_id' => $node->user_id, 'type' => 'transform', 'name' => 'reader', 'dsl_config' => []], [$node->id]);

    expect($store->currentVersionId($node->id, $reader->id))->toBeNull();

    $result = (new ResultStore)->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['x' => 1]],
    ]));

    expect($store->currentVersionId($node->id, $reader->id))->toBe($result->version->id);
});

it('currentVersionId refuses a node the reader does not declare as a parent', function (string $kind) {
    $reader = rowStoreNode();
    $target = match ($kind) {
        'undeclared' => rowStoreNode()->id,
        'missing' => (string) Str::uuid(),
        // Postgres rejects a malformed UUID; that must become the same exception.
        'malformed' => 'does-not-exist',
    };

    expect(fn () => (new GraphRowStore)->currentVersionId($target, $reader->id))
        ->toThrow(NodeNotReadableException::class);
})->with(['undeclared', 'missing', 'malformed']);

it('mergeLookup returns only the rows matching the given values, for the given version', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;

    $v1 = (new ResultStore)->store($node, new RowSet([
        ['row_key' => 'a', 'data' => ['sku' => 'A1', 'price' => 10]],
        ['row_key' => 'b', 'data' => ['sku' => 'B1', 'price' => 20]],
    ]))->version->id;

    $matches = $store->mergeLookup($node->id, $v1, 'sku', ['A1', 'nonexistent']);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['data'])->toBe(['sku' => 'A1', 'price' => 10]);
});

it('mergeLookup is pinned to the given version, not whatever is current now', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;

    $v1 = (new ResultStore)->store($node, new RowSet([
        ['row_key' => 'a', 'data' => ['sku' => 'A1', 'price' => 10]],
    ]))->version->id;

    // The node moves to a new version.
    (new ResultStore)->store($node->fresh(), new RowSet([
        ['row_key' => 'a', 'data' => ['sku' => 'A1', 'price' => 999]],
    ]));

    // A lookup pinned to the old version sees the old price.
    $matches = $store->mergeLookup($node->id, $v1, 'sku', ['A1']);

    expect($matches[0]['data']['price'])->toBe(10);
});

it('mergeLookup returns an empty array for an empty value list, without querying', function () {
    $node = rowStoreNode();
    $store = new GraphRowStore;

    expect($store->mergeLookup($node->id, (string) Str::uuid(), 'sku', []))->toBe([]);
});
