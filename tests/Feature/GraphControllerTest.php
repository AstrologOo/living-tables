<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('index returns nodes with their status and the edges between them', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $service = new GraphService;

    $parent = $service->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'Parent', 'dsl_config' => [],
    ]);
    $child = $service->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'Child', 'dsl_config' => [],
    ], [$parent->id]);

    $response = $this->getJson('/api/graph')->assertOk();

    $nodes = $response->json('nodes');
    $edges = $response->json('edges');

    expect($nodes)->toContain(['id' => $parent->id, 'name' => 'Parent', 'type' => 'source', 'status' => 'stale'])
        ->and($nodes)->toContain(['id' => $child->id, 'name' => 'Child', 'type' => 'transform', 'status' => 'stale'])
        ->and($edges)->toContain(['node_id' => $child->id, 'depends_on_id' => $parent->id]);
});

it('index does not return another user\'s nodes or edges', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $service = new GraphService;

    $service->createNode(['user_id' => $owner->id, 'type' => 'source', 'name' => 'Mine', 'dsl_config' => []]);

    Sanctum::actingAs($other);
    $response = $this->getJson('/api/graph')->assertOk();

    expect($response->json('nodes'))->toBe([])
        ->and($response->json('edges'))->toBe([]);
});

it('impact reports the affected count and node ids for a chain', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $service = new GraphService;

    $a = $service->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $b = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'B', 'dsl_config' => []], [$a->id]);
    $c = $service->createNode(['user_id' => $user->id, 'type' => 'transform', 'name' => 'C', 'dsl_config' => []], [$b->id]);

    $response = $this->getJson("/api/nodes/{$a->id}/impact")->assertOk();

    expect($response->json('node_id'))->toBe($a->id)
        ->and($response->json('affected_count'))->toBe(2)
        ->and($response->json('affected_node_ids'))->toEqualCanonicalizing([$b->id, $c->id]);
});

it('impact reports zero for a leaf node', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $this->getJson("/api/nodes/{$node->id}/impact")
        ->assertOk()
        ->assertJson(['affected_count' => 0, 'affected_node_ids' => []]);
});

it('impact returns 404 for a nonexistent node', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/nodes/'.Str::orderedUuid().'/impact')->assertNotFound();
});

it('impact returns 404 for a node owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $owner->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    Sanctum::actingAs($other);
    $this->getJson("/api/nodes/{$node->id}/impact")->assertNotFound();
});
