<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\SchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('claims a parentless stale node, then blocks its child until the parent is fresh', function () {
    $user = User::factory()->create();
    $graph = new GraphService;
    $scheduler = new SchedulerService;

    $a = $graph->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
    ]);

    $b = $graph->createNode([
        'user_id' => $user->id,
        'type' => 'transform',
        'name' => 'B',
        'dsl_config' => [],
    ], [$a->id]);

    // Both start stale (migration default). Only A has no non-fresh parents.
    $claimed = $scheduler->claimReadyNode();

    expect($claimed->id)->toBe($a->id)
        ->and($claimed->status)->toBe('processing')
        ->and($a->fresh()->status)->toBe('processing');

    // A is now processing (not fresh) — B stays blocked, nothing else is stale.
    expect($scheduler->claimReadyNode())->toBeNull();

    // Once A is fresh, B becomes eligible.
    $a->update(['status' => 'fresh']);

    $claimed = $scheduler->claimReadyNode();

    expect($claimed->id)->toBe($b->id)
        ->and($claimed->status)->toBe('processing')
        ->and($b->fresh()->status)->toBe('processing');

    expect($scheduler->claimReadyNode())->toBeNull();
});

it('returns null when there is nothing stale', function () {
    expect((new SchedulerService)->claimReadyNode())->toBeNull();
});
