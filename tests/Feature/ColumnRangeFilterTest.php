<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A signed-in user's node with prices on both sides of 100..500, its bounds included. */
function rangeNode(): Node
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [], 'columns_schema' => [
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ]]);
    // 20 and 1000 would fall inside the range if prices were compared as text.
    $prices = ['A' => 20, 'B' => 100, 'C' => 250, 'D' => 500, 'E' => 501, 'F' => 1000, 'G' => 'on request'];
    $rows = [];
    foreach ($prices as $sku => $price) {
        $rows[] = ['row_key' => $sku, 'data' => ['sku' => $sku, 'price' => $price]];
    }
    (new ResultStore)->store($node, new RowSet($rows));

    return $node->fresh();
}

const PRICE_RANGE = [['cmp' => '>=', 'value' => '100'], ['cmp' => '<=', 'value' => '500']];

it('reads rows within a range on one column', function () {
    $node = rangeNode();
    $query = 'filter[price][0][cmp]=>=&filter[price][0][value]=100&filter[price][1][cmp]=<=&filter[price][1][value]=500';

    $response = $this->getJson("/api/nodes/{$node->id}/rows?{$query}&sort=price")->assertOk();

    expect($response->json('data.*.sku'))->toBe(['B', 'C', 'D'])
        ->and($response->json('meta.total'))->toBe(3);
});

it('aggregates the rows within a range', function () {
    $node = rangeNode();
    $query = http_build_query([
        'filter' => ['price' => PRICE_RANGE],
        'aggregate' => ['n' => ['fn' => 'count'], 'total' => ['fn' => 'sum', 'col' => 'price']],
    ]);

    $this->getJson("/api/nodes/{$node->id}/aggregate?{$query}")->assertOk()
        ->assertJsonPath('data.n', 3)
        ->assertJsonPath('data.total', 850);
});

it('joins every condition of a column and of other columns with AND', function () {
    $node = rangeNode();
    $query = http_build_query(['filter' => [
        'price' => [...PRICE_RANGE, ['cmp' => '!=', 'value' => '250']],
        'sku' => 'D',
    ]]);

    expect($this->getJson("/api/nodes/{$node->id}/rows?{$query}")->assertOk()->json('data.*.sku'))->toBe(['D']);
});

it('saves a view with a range, returns it as a list and filters by it', function () {
    $node = rangeNode();

    $id = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'Range', 'filter' => ['price' => PRICE_RANGE], 'sort_field' => 'price'])
        ->assertCreated()->assertJsonPath('filter.price', PRICE_RANGE)->json('id');

    expect($this->getJson("/api/nodes/{$node->id}/views")->assertOk()->json('0.filter.price'))->toBe(PRICE_RANGE)
        ->and(NodeView::find($id)->filter)->toEqual(['price' => PRICE_RANGE])
        ->and($this->getJson("/api/views/{$id}/rows")->assertOk()->json('data.*.sku'))->toBe(['B', 'C', 'D']);
});

it('saves a list of one condition as that condition, and spells out a missing cmp in a list', function () {
    $node = rangeNode();

    $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'One', 'filter' => ['price' => [['cmp' => '>', 'value' => 500]]]])
        ->assertCreated()->assertExactJsonStructure(['id', 'user_id', 'node_id', 'name', 'filter' => ['price' => ['cmp', 'value']], 'sort_field', 'sort_order', 'unique_field', 'limit_count', 'created_at'])
        ->assertJsonPath('filter.price', ['cmp' => '>', 'value' => 500]);

    $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'Two', 'filter' => ['sku' => [['value' => 'B'], ['cmp' => '!=', 'value' => 'C']]]])
        ->assertCreated()
        ->assertJsonPath('filter.sku', [['cmp' => '==', 'value' => 'B'], ['cmp' => '!=', 'value' => 'C']]);
});

it('reads views saved before lists were accepted', function (mixed $criterion, array $expected) {
    $node = rangeNode();
    $view = NodeView::create(['user_id' => $node->user_id, 'node_id' => $node->id, 'name' => 'old', 'filter' => ['price' => $criterion], 'sort_field' => 'price']);

    expect($this->getJson("/api/views/{$view->id}/rows")->assertOk()->json('data.*.sku'))->toBe($expected);
})->with([
    'a bare value' => ['250', ['C']],
    'one condition' => [['cmp' => '>', 'value' => '500'], ['E', 'F']],
]);

it('answers 422 to a malformed list of conditions, wherever it is read', function (array $conditions, string $message) {
    $node = rangeNode();
    $filter = ['price' => $conditions];
    $view = NodeView::create(['user_id' => $node->user_id, 'node_id' => $node->id, 'name' => 'bad', 'filter' => $filter]);

    $created = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'Bad', 'filter' => $filter])->assertStatus(422);
    expect($created->json('error'))->toContain($message)
        ->and(NodeView::where('node_id', $node->id)->count())->toBe(1)
        ->and($this->getJson("/api/views/{$view->id}/rows")->assertStatus(422)->json('error'))->toContain($message);

    // A query string can't carry an empty list.
    if ($conditions !== []) {
        $query = http_build_query(['filter' => $filter]);

        foreach (["rows?{$query}", "aggregate?aggregate[n][fn]=count&{$query}"] as $url) {
            expect($this->getJson("/api/nodes/{$node->id}/{$url}")->assertStatus(422)->json('message'))->toContain($message);
        }
    }
})->with([
    'empty list' => [[], 'list of {cmp, value}'],
    'a bare value in the list' => [[['cmp' => '>', 'value' => 1], '5'], 'list of {cmp, value}'],
    'no value' => [[['cmp' => '>']], 'list of {cmp, value}'],
    'an unknown key' => [[['cmp' => '>', 'value' => 1, 'or' => '2']], 'list of {cmp, value}'],
    'a list in the list' => [[[['cmp' => '>', 'value' => 1]]], 'list of {cmp, value}'],
    'an unknown cmp' => [[['cmp' => '>', 'value' => 1], ['cmp' => 'like', 'value' => 2]], 'Unsupported filter cmp'],
    'a value that is a list' => [[['cmp' => '>', 'value' => [1]]], 'single text or number value'],
    'not a number' => [[['cmp' => '>=', 'value' => 100], ['cmp' => '<=', 'value' => 'abc']], 'needs a number'],
    'too many conditions' => [array_fill(0, 21, ['cmp' => '>', 'value' => 1]), 'at most 20'],
]);

it('takes as many as 20 conditions on a column', function () {
    $node = rangeNode();
    $query = http_build_query(['filter' => ['price' => array_fill(0, 20, ['cmp' => '>', 'value' => 500])]]);

    expect($this->getJson("/api/nodes/{$node->id}/rows?{$query}")->assertOk()->json('meta.total'))->toBe(2);
});
