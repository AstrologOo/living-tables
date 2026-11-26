<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function aggregateNode(): Node
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => [
            'col_sku' => ['name' => 'sku', 'type' => 'string'],
            'col_price' => ['name' => 'price', 'type' => 'number'],
        ],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_sku' => 'A', 'col_price' => 10]],
        ['row_key' => 'r2', 'data' => ['col_sku' => 'B', 'col_price' => 20]],
        ['row_key' => 'r3', 'data' => ['col_sku' => 'A', 'col_price' => 30]],
    ]));

    return $node;
}

it('computes sum, avg, min, max, count over a numeric column', function () {
    $node = aggregateNode();

    $response = $this->getJson(
        "/api/nodes/{$node->id}/aggregate".
        '?aggregate[total][fn]=sum&aggregate[total][col]=col_price'.
        '&aggregate[avg_price][fn]=avg&aggregate[avg_price][col]=col_price'.
        '&aggregate[lo][fn]=min&aggregate[lo][col]=col_price'.
        '&aggregate[hi][fn]=max&aggregate[hi][col]=col_price'.
        '&aggregate[n][fn]=count'
    )->assertOk();

    expect($response->json('data'))->toEqual([
        'total' => 60,
        'avg_price' => 20,
        'lo' => 10,
        'hi' => 30,
        'n' => 3,
    ]);
});

it('respects the same filter vocabulary as the rows endpoint', function () {
    $node = aggregateNode();

    $response = $this->getJson(
        "/api/nodes/{$node->id}/aggregate?filter[col_sku]=A".
        '&aggregate[total][fn]=sum&aggregate[total][col]=col_price'
    )->assertOk();

    expect($response->json('data'))->toEqual(['total' => 40]);
});

it('computes count_distinct', function () {
    $node = aggregateNode();

    $response = $this->getJson(
        "/api/nodes/{$node->id}/aggregate?aggregate[skus][fn]=count_distinct&aggregate[skus][col]=col_sku"
    )->assertOk();

    expect($response->json('data'))->toBe(['skus' => 2]);
});

it('rejects an unknown aggregate function with 422', function () {
    $node = aggregateNode();

    $this->getJson(
        "/api/nodes/{$node->id}/aggregate?aggregate[x][fn]=bogus&aggregate[x][col]=col_price"
    )->assertStatus(422);
});

it('rejects an aggregate on an undeclared column with 422', function () {
    $node = aggregateNode();

    $this->getJson(
        "/api/nodes/{$node->id}/aggregate?aggregate[x][fn]=sum&aggregate[x][col]=col_bogus"
    )->assertStatus(422);
});

it('rejects a request with no aggregates at all with 422', function () {
    $node = aggregateNode();

    $this->getJson("/api/nodes/{$node->id}/aggregate")->assertStatus(422);
});

it('rejects an unsafe aggregate alias with 422', function () {
    $node = aggregateNode();

    $this->getJson(
        '/api/nodes/'.$node->id.'/aggregate?'.http_build_query([
            'aggregate' => ['bad" OR 1=1--' => ['fn' => 'sum', 'col' => 'col_price']],
        ])
    )->assertStatus(422);
});

it('returns nulls for a node with no version yet', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);

    $response = $this->getJson(
        "/api/nodes/{$node->id}/aggregate?aggregate[total][fn]=sum&aggregate[total][col]=col_price"
    )->assertOk();

    expect($response->json('data'))->toBe(['total' => null]);
});

/** A node with a numeric and a text column, owned by a signed-in user. */
function mixedColumnsNode(): string
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [], 'columns_schema' => [
        'price' => ['name' => 'price', 'type' => 'number'],
        'code' => ['name' => 'code', 'type' => 'string'],
    ]]);
    (new ResultStore)->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['price' => 10, 'code' => '007']],
        ['row_key' => '2', 'data' => ['price' => 20, 'code' => '042']],
    ]));

    return $node->id;
}

it('returns every numeric result as a number', function () {
    $node = mixedColumnsNode();
    $query = collect(['count', 'count_distinct', 'sum', 'avg', 'min', 'max', 'median'])
        ->map(fn (string $fn) => "aggregate[{$fn}][fn]={$fn}&aggregate[{$fn}][col]=price")->implode('&');

    $data = $this->getJson("/api/nodes/{$node}/aggregate?{$query}")->assertOk()->json('data');

    foreach ($data as $value) {
        expect(is_int($value) || is_float($value))->toBeTrue();
    }
    expect($data)->toEqual(['count' => 2, 'count_distinct' => 2, 'sum' => 30, 'avg' => 15, 'min' => 10, 'max' => 20, 'median' => 15]);
});

it('keeps the text of a text column as it is', function () {
    $node = mixedColumnsNode();

    $this->getJson("/api/nodes/{$node}/aggregate?aggregate[lo][fn]=min&aggregate[lo][col]=code")
        ->assertOk()->assertExactJson(['data' => ['lo' => '007']]);
});

it('refuses arithmetic on a text column with 422', function (string $fn) {
    $node = mixedColumnsNode();

    $this->getJson("/api/nodes/{$node}/aggregate?aggregate[x][fn]={$fn}&aggregate[x][col]=code")->assertStatus(422);
})->with(['sum', 'avg', 'median']);
