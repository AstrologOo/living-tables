<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const TAILED_A = [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']];
const TAILED_B = [['op' => 'fetch', 'url' => 'https://x.test/b', 'format' => 'json']];

function tailedNode(): Node
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
}

function applyTailed(Node $node, array $config): void
{
    test()->postJson("/api/nodes/{$node->id}/apply", ['dsl_config' => $config])->assertOk();
}

/** Rolls back to the oldest history entry holding $executable. */
function rollbackTo(Node $node, array $executable): void
{
    $version = NodeConfigVersion::query()->where('node_id', $node->id)->orderBy('id')->get()
        ->first(fn (NodeConfigVersion $version) => $version->dsl_config == $executable);

    test()->postJson("/api/nodes/{$node->id}/config-versions/{$version->id}/rollback")->assertOk();
}

/** @return array<int, mixed>|null sort field, order and limit of the node's default view */
function defaultViewOf(Node $node): ?array
{
    $view = DB::table('node_views')->where('node_id', $node->id)->where('is_default', true)->first();

    return $view === null ? null : [$view->sort_field, $view->sort_order, $view->limit_count];
}

$byPrice = [['op' => 'sort', 'field' => 'price', 'order' => 'desc'], ['op' => 'limit', 'count' => 3]];

it('brings the default view back with the config', function () use ($byPrice) {
    $node = tailedNode();
    applyTailed($node, [...TAILED_A, ...$byPrice]);
    applyTailed($node, TAILED_B);
    expect(defaultViewOf($node))->toBeNull();

    rollbackTo($node, TAILED_A);

    expect(defaultViewOf($node))->toBe(['price', 'desc', 3])
        ->and($this->getJson("/api/nodes/{$node->id}/config-versions")->json('0.tail'))->toEqual($byPrice);
});

it('brings back the view a config had last, after an edit of the view alone', function () use ($byPrice) {
    $node = tailedNode();
    applyTailed($node, [...TAILED_A, ...$byPrice]);
    applyTailed($node, [...TAILED_A, ['op' => 'sort', 'field' => 'name', 'order' => 'asc']]);
    applyTailed($node, TAILED_B);

    rollbackTo($node, TAILED_A);

    expect(defaultViewOf($node))->toBe(['name', 'asc', null]);
});

it('removes the default view when the restored config had none', function () use ($byPrice) {
    $node = tailedNode();
    applyTailed($node, TAILED_A);
    applyTailed($node, [...TAILED_B, ...$byPrice]);

    rollbackTo($node, TAILED_A);

    expect(defaultViewOf($node))->toBeNull();
});

it('keeps the current view when an old history entry does not record one', function () use ($byPrice) {
    $node = tailedNode();
    applyTailed($node, TAILED_A);
    applyTailed($node, [...TAILED_B, ...$byPrice]);
    DB::table('node_config_versions')->where('node_id', $node->id)->update(['tail' => null]);

    rollbackTo($node, TAILED_A);

    expect($node->fresh()->dsl_config)->toEqual(TAILED_A)
        ->and(defaultViewOf($node))->toBe(['price', 'desc', 3]);
});
