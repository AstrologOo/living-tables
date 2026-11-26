<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('schedules the expired token cleanup daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'sanctum:prune-expired'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

it('removes tokens past their lifetime and keeps live ones', function () {
    $user = User::factory()->create();
    $user->createToken('old');
    $this->travel(32)->days();
    $user->createToken('new');

    $this->artisan('sanctum:prune-expired')->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['new']);
});
