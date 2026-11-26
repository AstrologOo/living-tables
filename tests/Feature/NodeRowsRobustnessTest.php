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

/**
 * A signed-in user's node whose `price` is declared a number, holding $prices.
 *
 * @param  array<string, mixed>  $prices  sku => price
 */
function pricedNode(array $prices = ['A' => 10, 'B' => 9]): Node
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [], 'columns_schema' => [
        'sku' => ['name' => 'sku', 'type' => 'string'],
        'price' => ['name' => 'price', 'type' => 'number'],
    ]]);
    $rows = [];
    foreach ($prices as $sku => $price) {
        $rows[] = ['row_key' => $sku, 'data' => ['sku' => $sku, 'price' => $price]];
    }
    (new ResultStore)->store($node, new RowSet($rows));

    return $node->fresh();
}

/** @param array<string, mixed> $attributes */
function viewOf(Node $node, array $attributes): string
{
    return NodeView::create(['user_id' => $node->user_id, 'node_id' => $node->id, 'name' => 'v', ...$attributes])->id;
}

/** Numbers, numbers written as text, and everything that is neither. */
const DIRTY_PRICES = [
    'ten' => 10, 'nine' => '9', 'hundred' => ' 100.5 ', 'word' => 'on request', 'blank' => '', 'null' => null,
    'flag' => true, 'list' => [1, 2], 'huge' => '1e999999', 'almost' => '12abc', 'nan' => 'NaN',
];

const NOT_NUMBERS = ['word', 'blank', 'null', 'flag', 'list', 'huge', 'almost', 'nan'];

it('answers 422 to a filter value that is not a number on a number column', function (string $cmp, string $value) {
    $node = pricedNode();
    $view = viewOf($node, ['filter' => ['price' => ['cmp' => $cmp, 'value' => $value]]]);
    $query = http_build_query(['filter' => ['price' => ['cmp' => $cmp, 'value' => $value]]]);

    foreach ([
        "/api/nodes/{$node->id}/rows?{$query}",
        "/api/nodes/{$node->id}/aggregate?aggregate[n][fn]=count&{$query}",
        "/api/views/{$view}/rows",
    ] as $url) {
        $response = $this->getJson($url)->assertStatus(422);

        expect($response->json('message') ?? $response->json('error'))->toContain('"price"')->toContain('number');
    }
})->with(['==', '!=', '>', '<', '>=', '<='])->with(['abc', '12abc', '1e999999', 'NaN']);

it('answers 422 to the short equality form too', function () {
    $node = pricedNode();

    $this->getJson("/api/nodes/{$node->id}/rows?filter[price]=abc")->assertStatus(422);
    $this->getJson('/api/views/'.viewOf($node, ['filter' => ['price' => 'abc']]).'/rows')->assertStatus(422);
});

it('compares numbers as numbers', function () {
    $node = pricedNode();

    expect($this->getJson("/api/nodes/{$node->id}/rows?filter[price]=10.0")->assertOk()->json('data.*.sku'))->toBe(['A'])
        ->and($this->getJson("/api/nodes/{$node->id}/rows?filter[price][cmp]=<&filter[price][value]=10")->assertOk()->json('data.*.sku'))->toBe(['B']);
});

it('sorts values that are not numbers as nulls, last in both directions', function (string $sort, array $numbers) {
    $node = pricedNode(DIRTY_PRICES);
    $view = viewOf($node, ['sort_field' => 'price', 'sort_order' => str_starts_with($sort, '-') ? 'desc' : 'asc']);

    foreach (["/api/nodes/{$node->id}/rows?sort={$sort}", "/api/views/{$view}/rows"] as $url) {
        $skus = $this->getJson($url)->assertOk()->json('data.*.sku');

        expect(array_slice($skus, 0, 3))->toBe($numbers)
            ->and(array_slice($skus, 3))->toEqualCanonicalizing(NOT_NUMBERS);
    }
})->with([
    'ascending' => ['price', ['nine', 'ten', 'hundred']],
    'descending' => ['-price', ['hundred', 'ten', 'nine']],
]);

it('leaves values that are not numbers out of numeric filters', function (string $cmp, array $expected) {
    $node = pricedNode(DIRTY_PRICES);
    $query = http_build_query(['filter' => ['price' => ['cmp' => $cmp, 'value' => '10']]]);
    $view = viewOf($node, ['filter' => ['price' => ['cmp' => $cmp, 'value' => '10']]]);

    foreach (["/api/nodes/{$node->id}/rows?{$query}", "/api/views/{$view}/rows"] as $url) {
        expect($this->getJson($url)->assertOk()->json('data.*.sku'))->toEqualCanonicalizing($expected);
    }
})->with([
    ['>', ['hundred']], ['<', ['nine']], ['>=', ['ten', 'hundred']], ['<=', ['nine', 'ten']],
    ['==', ['ten']], ['!=', ['nine', 'hundred']],
]);

it('leaves values that are not numbers out of aggregates', function () {
    $node = pricedNode(DIRTY_PRICES);
    $query = collect(['sum', 'avg', 'min', 'max', 'median'])
        ->map(fn (string $fn) => "aggregate[{$fn}][fn]={$fn}&aggregate[{$fn}][col]=price")->implode('&');

    $data = $this->getJson("/api/nodes/{$node->id}/aggregate?{$query}")->assertOk()->json('data');

    expect($data)->toEqual(['sum' => 119.5, 'avg' => 119.5 / 3, 'min' => 9, 'max' => 100.5, 'median' => 10]);
});

it('never answers a malformed read with an error 500', function (string $path) {
    $node = pricedNode();
    $view = viewOf($node, []);

    $status = $this->getJson(str_replace(['{node}', '{view}'], [$node->id, $view], $path))->status();

    expect($status)->toBeLessThan(500);
})->with([
    '/api/nodes/{node}/rows?sort[]=price',
    '/api/nodes/{node}/rows?filter=abc',
    '/api/nodes/{node}/rows?filter[price][value][]=1',
    '/api/nodes/{node}/rows?filter[sku][value][]=1',
    '/api/nodes/{node}/rows?filter[sku][]=1',
    '/api/nodes/{node}/rows?filter[sku][cmp][]=1',
    '/api/nodes/{node}/rows?filter[sku][cmp]=like&filter[sku][value]=1',
    '/api/nodes/{node}/rows?filter[sku]=a%00b',
    '/api/nodes/{node}/rows?filter[sku]=%ff',
    '/api/nodes/{node}/rows?filter[sku][cmp]=contains&filter[sku][value]=%ff',
    '/api/nodes/{node}/rows?filter[%ff]=1',
    '/api/nodes/{node}/rows?sort=%ff',
    '/api/nodes/{node}/rows?filter[sku][cmp]=%ff&filter[sku][value]=1',
    '/api/nodes/{node}/rows?page=999999999999999999',
    '/api/nodes/{node}/rows?page[]=1&per_page[]=1',
    '/api/nodes/{node}/aggregate',
    '/api/nodes/{node}/aggregate?aggregate=sum',
    '/api/nodes/{node}/aggregate?aggregate[x]=sum',
    '/api/nodes/{node}/aggregate?aggregate[x][fn][]=sum',
    '/api/nodes/{node}/aggregate?aggregate[x][fn]=%ff',
    '/api/nodes/{node}/aggregate?aggregate[x][fn]=sum&aggregate[x][col][]=price',
    '/api/nodes/{node}/aggregate?aggregate[x][fn]=count&aggregate[x][col][]=price',
    '/api/nodes/{node}/aggregate?aggregate[x][fn]=count&aggregate[x][col]=%ff',
    '/api/nodes/{node}/aggregate?aggregate[%ff][fn]=count',
    '/api/views/{view}/rows?page=999999999999999999',
    '/api/views/{view}/rows?page[]=1&per_page[]=1',
]);

it('never answers a malformed new view, or reading it, with an error 500', function (array $attributes) {
    $node = pricedNode();

    $response = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'v', ...$attributes]);

    expect($response->status())->toBeLessThan(500);
    if ($response->status() === 201) {
        expect($this->getJson("/api/views/{$response->json('id')}/rows")->status())->toBeLessThan(500);
    }
})->with([
    'long name' => [['name' => str_repeat('n', 300)]],
    'NUL in the name' => [['name' => "a\0b"]],
    'huge limit' => [['limit_count' => 3000000000]],
    'long sort field' => [['sort_field' => str_repeat('s', 300)]],
    'long unique field' => [['unique_field' => str_repeat('u', 300)]],
    'NUL in the sort field' => [['sort_field' => "a\0b"]],
    'NUL in the unique field' => [['unique_field' => "a\0b"]],
    'NUL in a filter value' => [['filter' => ['sku' => "a\0b"]]],
    'NUL in a filter column' => [['filter' => ["a\0b" => 'x']]],
    'list as a value' => [['filter' => ['sku' => ['cmp' => '==', 'value' => ['x']]]]],
    'list as a number' => [['filter' => ['price' => ['cmp' => '>', 'value' => ['x']]]]],
    'list without a value' => [['filter' => ['sku' => ['x', 'y']]]],
    'filter as a list' => [['filter' => ['x']]],
]);
