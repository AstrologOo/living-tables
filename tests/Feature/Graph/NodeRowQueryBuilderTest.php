<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeRowQueryBuilder;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeNodeWithRows(): Node
{
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['sku' => ['name' => 'sku', 'type' => 'string'], 'price' => ['name' => 'price', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['sku' => 'A1', 'price' => 500]],
        ['row_key' => 'r2', 'data' => ['sku' => 'A2', 'price' => 1500]],
    ]));

    return $node->fresh();
}

it('returns null when the node has no version yet', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['sku' => ['name' => 'sku', 'type' => 'string']],
    ]);

    expect((new NodeRowQueryBuilder)->build($node, [], null, false))->toBeNull();
});

it('throws for a filter on an undeclared column', function () {
    $node = makeNodeWithRows();

    expect(fn () => (new NodeRowQueryBuilder)->build($node, ['bogus' => 'x'], null, false))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for a sort on an undeclared column', function () {
    $node = makeNodeWithRows();

    expect(fn () => (new NodeRowQueryBuilder)->build($node, [], 'bogus', false))
        ->toThrow(InvalidArgumentException::class);
});

it('filters and sorts against the current version', function () {
    $node = makeNodeWithRows();

    $query = (new NodeRowQueryBuilder)->build($node, [], 'price', true);

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A2', 'A1']);
});

it('filters by an equality match', function () {
    $node = makeNodeWithRows();

    $query = (new NodeRowQueryBuilder)->build($node, ['sku' => 'A2'], null, false);

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A2']);
});

// range filters; 9 and 10 expose a text comparison, which would put "10" before "9"

function makeNodeWithCloseNumbers(): Node
{
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['sku' => ['name' => 'sku', 'type' => 'string'], 'price' => ['name' => 'price', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['sku' => 'A9', 'price' => 9]],
        ['row_key' => 'r2', 'data' => ['sku' => 'A10', 'price' => 10]],
    ]));

    return $node->fresh();
}

it('filters by a numeric > comparison, not lexicographic', function () {
    $node = makeNodeWithCloseNumbers();

    $query = (new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => '>', 'value' => 9]], null, false);

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A10']);
});

it('filters by >=, <, <=, and != comparisons', function () {
    $node = makeNodeWithRows(); // price 500, 1500

    expect((new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => '>=', 'value' => 500]], null, false)->get()->map(fn ($row) => $row->data['sku'])->sort()->values()->all())->toBe(['A1', 'A2'])
        ->and((new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => '<', 'value' => 1000]], null, false)->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A1'])
        ->and((new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => '<=', 'value' => 500]], null, false)->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A1'])
        ->and((new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => '!=', 'value' => 500]], null, false)->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A2']);
});

it('filters by contains on a string column', function () {
    $node = makeNodeWithRows();

    $query = (new NodeRowQueryBuilder)->build($node, ['sku' => ['cmp' => 'contains', 'value' => '2']], null, false);

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A2']);
});

it('throws for an unsupported cmp', function () {
    $node = makeNodeWithRows();

    expect(fn () => (new NodeRowQueryBuilder)->build($node, ['price' => ['cmp' => 'bogus', 'value' => 1]], null, false))
        ->toThrow(InvalidArgumentException::class);
});

it('formatRow flattens row_key and data onto one array', function () {
    $node = makeNodeWithRows();
    $row = (new NodeRowQueryBuilder)->build($node, [], null, false)->orderBy('row_key')->first();

    expect((new NodeRowQueryBuilder)->formatRow($row))->toBe([
        'row_key' => 'r1',
        'sku' => 'A1',
        'price' => 500,
    ]);
});

// unique_field

it('throws for a unique_field referencing an undeclared column', function () {
    $node = makeNodeWithRows();

    expect(fn () => (new NodeRowQueryBuilder)->build($node, [], null, false, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

function makeNodeWithDuplicateCategories(): Node
{
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => [
            'sku' => ['name' => 'sku', 'type' => 'string'],
            'category' => ['name' => 'category', 'type' => 'string'],
            'price' => ['name' => 'price', 'type' => 'number'],
        ],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['sku' => 'A1', 'category' => 'shoes', 'price' => 50]],
        ['row_key' => 'r2', 'data' => ['sku' => 'A2', 'category' => 'shoes', 'price' => 90]],
        ['row_key' => 'r3', 'data' => ['sku' => 'A3', 'category' => 'hats', 'price' => 20]],
    ]));

    return $node->fresh();
}

it('unique_field keeps only the first row seen per distinct value', function () {
    $node = makeNodeWithDuplicateCategories();

    $query = (new NodeRowQueryBuilder)->build($node, [], null, false, 'category');

    expect($query->get()->map(fn ($row) => $row->data['sku'])->sort()->values()->all())->toBe(['A1', 'A3']);
});

it('unique_field composes with a filter', function () {
    $node = makeNodeWithDuplicateCategories();

    $query = (new NodeRowQueryBuilder)->build($node, ['category' => 'shoes'], null, false, 'category');

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A1']);
});

it('unique_field composes with an outer sort over the deduplicated rows', function () {
    $node = makeNodeWithRows(); // A1=500, A2=1500, no duplicate skus at all
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['sku' => 'A1', 'price' => 500]],
        ['row_key' => 'r2', 'data' => ['sku' => 'A2', 'price' => 1500]],
        ['row_key' => 'r3', 'data' => ['sku' => 'A2', 'price' => 999]], // same sku, a second row
    ]));

    $query = (new NodeRowQueryBuilder)->build($node->fresh(), [], 'price', true, 'sku');

    expect($query->get()->map(fn ($row) => $row->data['sku'])->all())->toBe(['A2', 'A1']);
});
