<?php

use App\Models\User;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Models\UserLimit;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function usageLimiterNode(GraphService $graph, User $user): string
{
    return $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'N', 'dsl_config' => [],
    ])->id;
}

it('returns null when the user has no configured limits', function () {
    $user = User::factory()->create();

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 1_000_000))->toBeNull();
});

it('returns null for a null userId (no user context to check against)', function () {
    expect((new GraphUsageLimiter)->exceededReason(null, 1_000_000))->toBeNull();
});

it('returns a reason when this step\'s row count exceeds rows_per_node', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'rows_per_node' => 100]);

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 50))->toBeNull()
        ->and((new GraphUsageLimiter)->exceededReason((string) $user->id, 101))->toContain('101')->toContain('100');
});

it('returns null when tokens_per_month is set but nothing has been spent yet', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'tokens_per_month' => 1000]);

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 1))->toBeNull();
});

it('returns a reason once this month\'s run_stats tokens_spent reaches the monthly limit', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'tokens_per_month' => 1000]);
    $nodeId = usageLimiterNode(new GraphService, $user);

    RunStat::create([
        'user_id' => $user->id, 'node_id' => $nodeId, 'version_id' => null,
        'duration_ms' => 0, 'tokens_spent' => 1000, 'rows_total' => 0, 'rows_changed' => 0,
        'status' => 'success', 'created_at' => now(),
    ]);

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 1))
        ->toContain('1000');
});

it('ignores tokens_spent from a previous calendar month', function () {
    $user = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'tokens_per_month' => 1000]);
    $nodeId = usageLimiterNode(new GraphService, $user);

    RunStat::create([
        'user_id' => $user->id, 'node_id' => $nodeId, 'version_id' => null,
        'duration_ms' => 0, 'tokens_spent' => 5000, 'rows_total' => 0, 'rows_changed' => 0,
        'status' => 'success', 'created_at' => now()->subMonth()->startOfMonth(),
    ]);

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 1))->toBeNull();
});

it('does not let another user\'s tokens_spent count against this one', function () {
    $graph = new GraphService;
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    UserLimit::create(['user_id' => $user->id, 'tokens_per_month' => 1000]);
    $otherNodeId = usageLimiterNode($graph, $otherUser);

    RunStat::create([
        'user_id' => $otherUser->id, 'node_id' => $otherNodeId, 'version_id' => null,
        'duration_ms' => 0, 'tokens_spent' => 5000, 'rows_total' => 0, 'rows_changed' => 0,
        'status' => 'success', 'created_at' => now(),
    ]);

    expect((new GraphUsageLimiter)->exceededReason((string) $user->id, 1))->toBeNull();
});
