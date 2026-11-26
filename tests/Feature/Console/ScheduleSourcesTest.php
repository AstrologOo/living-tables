<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\RabbitMqTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));
    $channel->getConnection()->close();
});

it('marks a fresh source node stale and publishes a task when its schedule is due', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'schedule' => '* * * * *', // due every minute
    ]);
    $node->update(['status' => 'fresh']);

    $this->artisan('graph:schedule-sources')->assertSuccessful();

    expect($node->fresh()->status)->toBe('stale');

    $channel = (new RabbitMqTopology)->connect();

    $message = null;
    for ($i = 0; $i < 20 && $message === null; $i++) {
        $message = $channel->basic_get(config('rabbitmq.queue'));
        if ($message === null) {
            usleep(50_000);
        }
    }
    $channel->getConnection()->close();

    expect($message)->not->toBeNull()
        ->and(json_decode($message->getBody(), true))->toBe(['node_id' => $node->id]);
});

it('does not touch a source node whose schedule is not due', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'schedule' => '0 0 1 1 *', // once a year
    ]);
    $node->update(['status' => 'fresh']);

    $this->artisan('graph:schedule-sources')->assertSuccessful();

    expect($node->fresh()->status)->toBe('fresh');
});

it('ignores non-source nodes and nodes without a schedule', function () {
    $user = User::factory()->create();
    $graph = new GraphService;

    $transform = $graph->createNode([
        'user_id' => $user->id, 'type' => 'transform', 'name' => 'T',
        'dsl_config' => [], 'schedule' => '* * * * *',
    ]);
    $transform->update(['status' => 'fresh']);

    $noSchedule = $graph->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'S',
        'dsl_config' => [],
    ]);
    $noSchedule->update(['status' => 'fresh']);

    $this->artisan('graph:schedule-sources')->assertSuccessful();

    expect($transform->fresh()->status)->toBe('fresh')
        ->and($noSchedule->fresh()->status)->toBe('fresh');
});

it('ignores a due source node that is not currently fresh', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'schedule' => '* * * * *',
    ]);
    $node->update(['status' => 'processing']);

    $this->artisan('graph:schedule-sources')->assertSuccessful();

    expect($node->fresh()->status)->toBe('processing');
});

it('skips a malformed cron expression instead of crashing', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'schedule' => 'not a cron expression',
    ]);
    $node->update(['status' => 'fresh']);

    $this->artisan('graph:schedule-sources')->assertSuccessful();

    expect($node->fresh()->status)->toBe('fresh');
});
