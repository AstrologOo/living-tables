<?php

use App\Models\User;
use App\Modules\Graph\Models\NodeNotification;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NotificationService;
use App\Modules\Graph\ValueObjects\DiffResult;
use App\Modules\Shared\Contracts\RealtimeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\FakeRealtimeNotifier;

uses(RefreshDatabase::class);

it('notifyDataChanged records a notification with the diff counts', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifyDataChanged($node, new DiffResult(
        added: ['r1', 'r2'],
        changed: ['r3'],
        removed: [],
    ));

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();

    expect($notification->user_id)->toBe($user->id)
        ->and($notification->type)->toBe('data_changed')
        ->and($notification->payload)->toBe(['added' => 2, 'changed' => 1, 'removed' => 0])
        ->and($notification->read_at)->toBeNull();
});

it('notifyDataChanged does nothing for an empty diff', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifyDataChanged($node, DiffResult::empty());

    expect(NodeNotification::query()->where('node_id', $node->id)->exists())->toBeFalse();
});

it('notifySchemaDrift records a notification with the missing/new fields', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifySchemaDrift($node, ['col_price' => 'price'], ['discount']);

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();

    // Postgres jsonb doesn't keep key order.
    expect($notification->user_id)->toBe($user->id)
        ->and($notification->type)->toBe('schema_drift')
        ->and($notification->payload)->toEqualCanonicalizing(['missing_fields' => ['col_price' => 'price'], 'new_fields' => ['discount']])
        ->and($notification->read_at)->toBeNull();
});

it('notifyFailed records a notification with the failure message', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifyFailed($node, 'boom');

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();

    expect($notification->user_id)->toBe($user->id)
        ->and($notification->type)->toBe('failed')
        ->and($notification->payload)->toBe(['message' => 'boom'])
        ->and($notification->read_at)->toBeNull();
});

// realtime push

it('pushes a notification.created event to the owning user via RealtimeNotifier', function () {
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifyFailed($node, 'boom');

    $notification = NodeNotification::query()->where('node_id', $node->id)->sole();

    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['userId'])->toBe($user->id)
        ->and($fake->sent[0]['event'])->toBe('notification.created')
        ->and($fake->sent[0]['payload']['id'])->toBe($notification->id)
        ->and($fake->sent[0]['payload']['type'])->toBe('failed');
});

it('does not push anything for an empty diff (no notification was created either)', function () {
    $fake = new FakeRealtimeNotifier;
    app()->instance(RealtimeNotifier::class, $fake);

    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    (new NotificationService)->notifyDataChanged($node, DiffResult::empty());

    expect($fake->sent)->toBe([]);
});
