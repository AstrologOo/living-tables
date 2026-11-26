<?php

use App\Models\User;
use App\Modules\Graph\Models\NodeNotification;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Shared\Contracts\RealtimeNotifier;
use App\Support\PublicErrorMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Fakes\FakeRealtimeNotifier;

uses(RefreshDatabase::class);

it('returns an orphaned processing node to stale, counting a failure and delaying the retry', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'processing', 'updated_at' => now()->subMinutes(5)]);

    $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->consecutive_failures)->toBe(1)
        ->and($node->fresh()->retry_at->equalTo(now()->addSeconds(30)))->toBeTrue()
        ->and((new SchedulerService)->claimReadyNode())->toBeNull()
        ->and(NodeNotification::query()->count())->toBe(0);
});

it('gives up on a node recovered for the third time in a row and tells its owner', function () {
    $this->app->instance(RealtimeNotifier::class, $pushes = new FakeRealtimeNotifier);
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    $statuses = [];

    foreach (range(1, 3) as $attempt) {
        expect((new SchedulerService)->claimReadyNode()?->id)->toBe($node->id);
        $this->travel(5)->minutes(); // past both the age guard and the retry delay

        $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

        $statuses[] = $node->fresh()->status;
        $this->travel(5)->minutes();
    }

    expect($statuses)->toBe(['stale', 'stale', 'failed'])
        ->and($node->fresh()->consecutive_failures)->toBe(3)
        ->and($node->fresh()->retry_at)->toBeNull()
        ->and($node->fresh()->claim_token)->toBeNull()
        ->and(RunStat::query()->where('node_id', $node->id)->where('status', 'failed')->count())->toBe(1)
        ->and(NodeNotification::query()->where('node_id', $node->id)->sole()->toJson(JSON_UNESCAPED_UNICODE))
        ->toContain(PublicErrorMessage::RUN_INTERRUPTED)
        ->and(collect($pushes->sent)->where('event', 'node.status_changed')->pluck('payload.status')->all())
        ->toBe(['failed']);
});

it('leaves a processing node alone when its lock is genuinely still held', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'processing', 'updated_at' => now()->subMinutes(5)]);

    $lock = Cache::store('redis')->lock("node:{$node->id}", 300);
    $lock->get();

    try {
        $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

        expect($node->fresh()->status)->toBe('processing');
    } finally {
        $lock->release();
    }
});

it('leaves a recently-claimed processing node alone even with no lock yet, to avoid the claim race', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'processing']); // updated_at defaults to "now"

    $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

    expect($node->fresh()->status)->toBe('processing');
});

it('ignores nodes that are not in processing at all', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $node->update(['status' => 'stale', 'updated_at' => now()->subMinutes(5)]);

    $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale');
});

it('clears the claim token of a recovered node', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);
    $claimed = (new SchedulerService)->claimReadyNode();
    expect($claimed->claim_token)->not->toBeNull();
    $this->travel(5)->minutes();

    $this->artisan('graph:recover-stuck-nodes')->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->claim_token)->toBeNull();
});
