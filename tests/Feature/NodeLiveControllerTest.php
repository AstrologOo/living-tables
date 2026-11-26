<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns null when the node has no live channel entry', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $response = $this->getJson("/api/nodes/{$node->id}/live")->assertOk();

    // json() would turn `null` into [], so check the raw body.
    expect($response->getContent())->toBe('null');
});

it('returns the live channel contents when present, marked as a draft', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    Cache::store('redis')->put("node:{$node->id}:live", [
        'processed' => 5,
        'rows' => [['row_key' => 'r1', 'data' => ['x' => 1]]],
        'updated_at' => '2026-01-01T00:00:00+00:00',
    ], 60);

    $response = $this->getJson("/api/nodes/{$node->id}/live")->assertOk();

    expect($response->json('processed'))->toBe(5)
        ->and($response->json('rows'))->toBe([['row_key' => 'r1', 'data' => ['x' => 1]]])
        ->and($response->json('draft'))->toBeTrue();

    Cache::store('redis')->forget("node:{$node->id}:live");
});
