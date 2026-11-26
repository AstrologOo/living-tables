<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A second session only sees committed rows, so no wrapping transaction here; the test cleans up itself.

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks need Postgres; sqlite ignores FOR UPDATE.');
    }

    if (! RefreshDatabaseState::$migrated) {
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }

    config(['database.connections.second_session' => config('database.connections.pgsql')]);

    // Fail fast if claiming ever waits instead of skipping the locked row.
    DB::statement("SET lock_timeout = '2s'");

    $this->user = User::factory()->create();
});

afterEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    DB::connection('second_session')->rollBack();
    DB::purge('second_session');
    DB::statement('RESET lock_timeout');
    $this->user?->delete(); // cascades to its nodes
});

/**
 * Locks the node's row from a second session.
 */
function holdRow(string $nodeId, string $lock = 'FOR UPDATE'): void
{
    DB::connection('second_session')->beginTransaction();
    DB::connection('second_session')->select("SELECT id FROM nodes WHERE id = ? {$lock}", [$nodeId]);
}

/** @return array{0: Node, 1: Node, 2: array<string, ?string>} fresh parent, claimed child, parent versions */
function claimedChild(int $userId): array
{
    $graph = new GraphService;
    $attrs = ['user_id' => $userId, 'dsl_config' => []];
    $parent = $graph->createNode([...$attrs, 'type' => 'source', 'name' => 'parent']);
    $parent->update(['status' => 'fresh']);
    $graph->createNode([...$attrs, 'type' => 'transform', 'name' => 'child'], [$parent->id]);

    return [$parent, (new SchedulerService)->claimReadyNode(), [$parent->id => null]];
}

function oneRow(): RowSet
{
    return new RowSet([['row_key' => 'k', 'data' => ['a' => 1]]]);
}

it('commits only after another session releases the node\'s row (FOR UPDATE)', function () {
    [, $child, $parents] = claimedChild($this->user->id);
    // FOR KEY SHARE blocks FOR UPDATE but not UPDATE, so this proves the commit locks first.
    holdRow($child->id, 'FOR KEY SHARE');

    expect(fn () => (new ResultStore)->store($child, oneRow(), $parents))
        ->toThrow(QueryException::class, 'lock timeout')
        ->and(DB::table('node_versions')->where('node_id', $child->id)->count())->toBe(0);

    DB::connection('second_session')->rollBack();

    expect((new ResultStore)->store($child, oneRow(), $parents)->status)->toBe('fresh');
});

it('waits for a parent that is committing, but not for another reader of it (FOR SHARE)', function () {
    [$parent, $child, $parents] = claimedChild($this->user->id);
    holdRow($parent->id);

    expect(fn () => (new ResultStore)->store($child, oneRow(), $parents))
        ->toThrow(QueryException::class, 'lock timeout')
        ->and($child->fresh()->status)->toBe('processing');

    DB::connection('second_session')->rollBack();
    holdRow($parent->id, 'FOR SHARE');

    expect((new ResultStore)->store($child, oneRow(), $parents)->status)->toBe('fresh');
});

it('skips a node another session holds FOR UPDATE and claims the next one', function () {
    $graph = new GraphService;
    $attrs = ['user_id' => $this->user->id, 'type' => 'source', 'dsl_config' => []];
    $locked = $graph->createNode([...$attrs, 'name' => 'locked']);
    $free = $graph->createNode([...$attrs, 'name' => 'free']);
    DB::table('nodes')->where('id', $locked->id)->update(['created_at' => now()->subMinute()]);

    holdRow($locked->id);

    $scheduler = new SchedulerService;

    expect($scheduler->claimReadyNode()?->id)->toBe($free->id)
        ->and($scheduler->claimReadyNode())->toBeNull();

    DB::connection('second_session')->rollBack();

    expect($scheduler->claimReadyNode()?->id)->toBe($locked->id);
});

it('locks parents, then the node, then its children, ancestors first within each group', function () {
    $graph = new GraphService;
    $attrs = ['user_id' => $this->user->id, 'type' => 'transform', 'dsl_config' => []];
    // Created descendants first, so that ordering by id alone would be wrong.
    [$lowerChild, $upperChild, $node, $lowerParent, $upperParent] = array_map(
        fn (string $name) => $graph->createNode([...$attrs, 'name' => $name]),
        ['lower child', 'upper child', 'node', 'lower parent', 'upper parent'],
    );

    foreach ([
        [$lowerParent, $upperParent], [$node, $upperParent], [$node, $lowerParent],
        [$upperChild, $node], [$lowerChild, $node], [$lowerChild, $upperChild],
    ] as [$from, $to]) {
        $graph->addEdge($from->id, $to->id);
    }

    $node->update(['status' => 'processing', 'claim_token' => (string) Str::uuid()]);
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked) {
        if (preg_match('/for (share|update|no key update)$/', $query->sql, $lock)) {
            $locked[] = [$query->bindings[0], $lock[1]];
        }
    });

    (new ResultStore)->store($node, oneRow(), []);

    expect($locked)->toBe([
        [$upperParent->id, 'share'],
        [$lowerParent->id, 'share'],
        [$node->id, 'update'],
        [$upperChild->id, 'no key update'],
        [$lowerChild->id, 'no key update'],
    ]);
});

it('waits for a child that is committing before it writes anything', function () {
    [$parent, $child] = claimedChild($this->user->id);
    $child->update(['status' => 'fresh', 'claim_token' => null]);
    $parent->update(['status' => 'processing', 'claim_token' => (string) Str::uuid()]);

    holdRow($child->id, 'FOR NO KEY UPDATE');

    expect(fn () => (new ResultStore)->store($parent, oneRow(), []))
        ->toThrow(QueryException::class, 'lock timeout')
        ->and(DB::table('node_versions')->where('node_id', $parent->id)->count())->toBe(0);

    DB::connection('second_session')->rollBack();

    expect((new ResultStore)->store($parent, oneRow(), [])->staleChildIds)->toBe([$child->id])
        ->and($child->fresh()->status)->toBe('stale');
});
