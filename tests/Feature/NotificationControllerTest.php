<?php

use App\Models\User;
use App\Modules\Graph\Models\NodeNotification;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NotificationService;
use App\Modules\Graph\ValueObjects\DiffResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeNotification(User $user): NodeNotification
{
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);

    (new NotificationService)->notifyDataChanged($node, new DiffResult(added: ['r1'], changed: [], removed: []));

    return NodeNotification::query()->where('node_id', $node->id)->sole();
}

it('index lists notifications for the authenticated user, newest first', function () {
    $user = User::factory()->create();
    $first = makeNotification($user);
    $second = makeNotification($user);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/notifications')->assertOk();

    expect($response->json('0.id'))->toBe($second->id)
        ->and($response->json('1.id'))->toBe($first->id);
});

it('index does not return another user\'s notifications', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    makeNotification($otherUser);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/notifications')->assertOk();

    expect($response->json())->toBe([]);
});

it('index filters to unread only when asked', function () {
    $user = User::factory()->create();
    $unread = makeNotification($user);
    $read = makeNotification($user);
    $read->update(['read_at' => now()]);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/notifications?unread_only=1')->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($unread->id);
});

it('index requires authentication', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
});

it('markRead sets read_at', function () {
    $user = User::factory()->create();
    $notification = makeNotification($user);

    Sanctum::actingAs($user);
    $response = $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();

    expect($response->json('read_at'))->not->toBeNull()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

it('markRead returns 404 for a nonexistent notification', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/notifications/'.Str::orderedUuid().'/read')->assertNotFound();
});

it('markRead returns 404 for another user\'s notification', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $notification = makeNotification($owner);

    Sanctum::actingAs($other);
    $this->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();
    expect($notification->fresh()->read_at)->toBeNull();
});
