<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('--returned clears schema_drift back to stale', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);
    $node->update(['status' => 'schema_drift']);

    $this->artisan('graph:resolve-drift', ['node' => $node->id, '--returned' => true])
        ->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale');
});

it('--remove drops the column and clears schema_drift', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);
    $node->update(['status' => 'schema_drift']);

    $this->artisan('graph:resolve-drift', ['node' => $node->id, '--remove' => 'col_price'])
        ->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->columns_schema)->toBe([]);
});

it('fails when neither --returned nor --remove is given', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'schema_drift']);

    $this->artisan('graph:resolve-drift', ['node' => $node->id])
        ->assertFailed();
});

it('fails when both --returned and --remove are given', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);
    $node->update(['status' => 'schema_drift']);

    $this->artisan('graph:resolve-drift', ['node' => $node->id, '--returned' => true, '--remove' => 'col_price'])
        ->assertFailed();
});

it('fails cleanly for a nonexistent node id', function () {
    $this->artisan('graph:resolve-drift', ['node' => (string) Str::orderedUuid(), '--returned' => true])
        ->assertFailed();
});
