<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\InvalidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks only direct fresh children as stale, not grandchildren', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $create = fn (string $name) => $graph->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => $name,
        'dsl_config' => [],
    ]);

    $a = $create('A');
    $b = $create('B');
    $c = $create('C');
    $graph->addEdge($b->id, $a->id); // B depends on A
    $graph->addEdge($c->id, $b->id); // C depends on B

    $a->update(['status' => 'fresh']);
    $b->update(['status' => 'fresh']);
    $c->update(['status' => 'fresh']);

    $affected = (new InvalidationService)->markChildrenStale($a->fresh());

    expect($affected)->toBe(1)
        ->and($b->fresh()->status)->toBe('stale')
        ->and($c->fresh()->status)->toBe('fresh'); // Only direct children are marked.
});

it('does not touch children that are not fresh', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $create = fn (string $name) => $graph->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => $name,
        'dsl_config' => [],
    ]);

    $a = $create('A');
    $processing = $create('Processing');
    $graph->addEdge($processing->id, $a->id);

    $a->update(['status' => 'fresh']);
    $processing->update(['status' => 'processing']);

    $affected = (new InvalidationService)->markChildrenStale($a->fresh());

    expect($affected)->toBe(0)
        ->and($processing->fresh()->status)->toBe('processing');
});

it('markChildrenStaleIds returns exactly the ids that transitioned', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $create = fn (string $name) => $graph->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => $name,
        'dsl_config' => [],
    ]);

    $a = $create('A');
    $b = $create('B');
    $c = $create('C');
    $graph->addEdge($b->id, $a->id);
    $graph->addEdge($c->id, $a->id);

    $a->update(['status' => 'fresh']);
    $b->update(['status' => 'fresh']);
    $c->update(['status' => 'processing']);

    $ids = (new InvalidationService)->markChildrenStaleIds($a->fresh());

    expect($ids)->toBe([$b->id]);
});
