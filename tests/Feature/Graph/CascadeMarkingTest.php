<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\InvalidationService;
use App\Modules\Graph\Services\NodeRunStateService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{0: Node, 1: Node, 2: Node} a claimed parent, its fresh child and fresh grandchild */
function claimedParentWithDescendants(): array
{
    $graph = new GraphService;
    $attrs = ['user_id' => User::factory()->create()->id, 'dsl_config' => []];
    $parent = $graph->createNode([...$attrs, 'type' => 'source', 'name' => 'parent']);
    $child = $graph->createNode([...$attrs, 'type' => 'transform', 'name' => 'child'], [$parent->id]);
    $grandchild = $graph->createNode([...$attrs, 'type' => 'transform', 'name' => 'grandchild'], [$child->id]);

    $parent->update(['status' => 'processing', 'claim_token' => (string) Str::uuid()]);
    $child->update(['status' => 'fresh']);
    $grandchild->update(['status' => 'fresh']);

    return [$parent, $child, $grandchild];
}

/** The result of a run, as a plain array or as a run's drafts. */
function cascadeRows(Node $node, string $kind): RowSet
{
    $rows = [['row_key' => 'k', 'row_hash' => hash('sha256', 'k'), 'data' => ['a' => 1]]];

    if ($kind === 'array') {
        return new RowSet($rows);
    }

    $runId = (string) Str::orderedUuid();
    (new GraphRowStore)->appendBatch($runId, $node->id, $rows);

    return RowSet::fromRun($runId, $node->id, 1);
}

dataset('row sets', ['array', 'run']);

it('marks the direct fresh children stale in the commit of a new version', function (string $kind) {
    [$parent, $child, $grandchild] = claimedParentWithDescendants();

    $result = (new ResultStore)->store($parent, cascadeRows($parent, $kind), []);

    expect($result->staleChildIds)->toBe([$child->id])
        ->and($child->fresh()->status)->toBe('stale')
        ->and($grandchild->fresh()->status)->toBe('fresh');
})->with('row sets');

it('leaves children alone when the content is unchanged', function (string $kind) {
    [$parent, $child] = claimedParentWithDescendants();
    (new ResultStore)->store($parent, cascadeRows($parent, $kind), []);
    // A fresh model: the old one still believes it is `fresh` and would write nothing.
    $child->fresh()->update(['status' => 'fresh']);
    $parent->update(['status' => 'processing', 'claim_token' => (string) Str::uuid()]);

    $result = (new ResultStore)->store($parent, cascadeRows($parent, $kind), []);

    expect($result->version)->toBeNull()
        ->and($result->staleChildIds)->toBe([])
        ->and($child->fresh()->status)->toBe('fresh');
})->with('row sets');

it('marks children stale even if the new version\'s node is left stale itself', function () {
    [$parent, $child] = claimedParentWithDescendants();
    DB::table('nodes')->where('id', $parent->id)->increment('data_revision'); // a webhook during the run
    $parent->data_revision = 0;

    $result = (new ResultStore)->store($parent, cascadeRows($parent, 'array'), []);

    expect($result->status)->toBe('stale')
        ->and($child->fresh()->status)->toBe('stale');
});

it('rolls back both the version and the children\'s marks when the database fails during the commit', function (string $kind) {
    [$parent, $child] = claimedParentWithDescendants();
    $token = $parent->claim_token;
    // Fails with the version written and the child already marked.
    $failing = new class extends InvalidationService
    {
        /** @var array<int, string> */
        public array $marked = [];

        public function markChildrenStaleIds(Node $node): array
        {
            $this->marked = parent::markChildrenStaleIds($node);
            DB::select('SELECT 1 FROM no_such_table');

            return $this->marked;
        }
    };
    $rows = cascadeRows($parent, $kind);

    expect(fn () => (new ResultStore($failing))->store($parent, $rows, []))->toThrow(QueryException::class);

    expect($failing->marked)->toBe([$child->id])
        ->and($child->fresh()->status)->toBe('fresh')
        ->and(DB::table('node_versions')->where('node_id', $parent->id)->count())->toBe(0)
        ->and($parent->fresh()->only(['status', 'claim_token', 'content_hash', 'current_version_id']))
        ->toBe(['status' => 'processing', 'claim_token' => $token, 'content_hash' => null, 'current_version_id' => null]);
})->with('row sets');

it('gives a failed child another run, keeping its failure count', function (string $kind) {
    [$parent, $child] = claimedParentWithDescendants();
    $child->fresh()->update(['status' => 'failed', 'consecutive_failures' => 3]);

    $result = (new ResultStore)->store($parent, cascadeRows($parent, $kind), []);

    expect($result->staleChildIds)->toBe([$child->id])
        ->and($child->fresh()->only(['status', 'consecutive_failures', 'retry_at']))
        ->toBe(['status' => 'stale', 'consecutive_failures' => 3, 'retry_at' => null]);
})->with('row sets');

it('fails a given-up child again after its one extra run', function () {
    [$parent, $child] = claimedParentWithDescendants();
    $child->fresh()->update(['status' => 'failed', 'consecutive_failures' => 3]);
    (new ResultStore)->store($parent, cascadeRows($parent, 'array'), []);
    $claimed = $child->fresh();
    $claimed->update(['status' => 'processing', 'claim_token' => (string) Str::uuid()]);

    expect((new NodeRunStateService)->retryLater($claimed))->toBe('failed')
        ->and($child->fresh()->consecutive_failures)->toBe(4);
});

it('leaves a schema_drift child for a human', function () {
    [$parent, $child] = claimedParentWithDescendants();
    $child->fresh()->update(['status' => 'schema_drift']);

    $result = (new ResultStore)->store($parent, cascadeRows($parent, 'array'), []);

    expect($result->staleChildIds)->toBe([])
        ->and($child->fresh()->status)->toBe('schema_drift');
});
