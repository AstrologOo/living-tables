<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns an empty table for a node with no version yet', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_sku' => ['name' => 'sku', 'type' => 'string']],
    ]);

    $response = $this->getJson("/api/nodes/{$node->id}/rows")->assertOk();

    $response->assertJson([
        'data' => [],
        'columns' => ['col_sku' => ['name' => 'sku', 'type' => 'string']],
        'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
    ]);
});

it('returns rows and columns for the current version', function () {
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
        ['row_key' => 'r1', 'data' => ['col_sku' => 'A1', 'col_price' => 10]],
        ['row_key' => 'r2', 'data' => ['col_sku' => 'B2', 'col_price' => 20]],
    ]));

    $response = $this->getJson("/api/nodes/{$node->id}/rows")->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJson(['meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1]]);

    $data = $response->json('data');
    expect($data)->toContain(['row_key' => 'r1', 'col_sku' => 'A1', 'col_price' => 10])
        ->and($data)->toContain(['row_key' => 'r2', 'col_sku' => 'B2', 'col_price' => 20]);
});

it('filters rows by an equality match on a declared column', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_sku' => ['name' => 'sku', 'type' => 'string']],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_sku' => 'A1']],
        ['row_key' => 'r2', 'data' => ['col_sku' => 'B2']],
    ]));

    $response = $this->getJson("/api/nodes/{$node->id}/rows?filter[col_sku]=A1")->assertOk();

    expect($response->json('data'))->toBe([['row_key' => 'r1', 'col_sku' => 'A1']]);
});

it('rejects a filter on an undeclared column with 422', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_sku' => ['name' => 'sku', 'type' => 'string']],
    ]);
    app(ResultStore::class)->store($node, new RowSet([['row_key' => 'r1', 'data' => ['col_sku' => 'A1']]]));

    $this->getJson("/api/nodes/{$node->id}/rows?filter[col_bogus]=x")->assertStatus(422);
});

it('sorts rows by a declared column, ascending or descending', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['col_price' => 30]],
        ['row_key' => 'r2', 'data' => ['col_price' => 10]],
        ['row_key' => 'r3', 'data' => ['col_price' => 20]],
    ]));

    $asc = $this->getJson("/api/nodes/{$node->id}/rows?sort=col_price")->assertOk();
    expect(array_column($asc->json('data'), 'row_key'))->toBe(['r2', 'r3', 'r1']);

    $desc = $this->getJson("/api/nodes/{$node->id}/rows?sort=-col_price")->assertOk();
    expect(array_column($desc->json('data'), 'row_key'))->toBe(['r1', 'r3', 'r2']);
});

it('rejects a sort on an undeclared column with 422', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_sku' => ['name' => 'sku', 'type' => 'string']],
    ]);

    $this->getJson("/api/nodes/{$node->id}/rows?sort=col_bogus")->assertStatus(422);
});

it('paginates in the database, capping per_page at 200', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_i' => ['name' => 'i', 'type' => 'integer']],
    ]);
    app(ResultStore::class)->store($node, new RowSet(
        array_map(fn (int $i) => ['row_key' => "r{$i}", 'data' => ['col_i' => $i]], range(1, 5))
    ));

    $response = $this->getJson("/api/nodes/{$node->id}/rows?per_page=2&page=2")->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJson(['meta' => ['current_page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3]]);
});

it('returns 404 for a nonexistent node', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/nodes/'.Str::orderedUuid().'/rows')->assertNotFound();
});

it('returns 404 for a node owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $owner->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    Sanctum::actingAs($other);
    $this->getJson("/api/nodes/{$node->id}/rows")->assertNotFound();
});
