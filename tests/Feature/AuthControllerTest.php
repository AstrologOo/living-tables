<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// register

it('registers a new user and returns a usable token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => 'password123',
    ])->assertCreated();

    expect($response->json('user.email'))->toBe('ada@example.test')
        ->and($response->json('user.password'))->toBeNull() // hidden
        ->and($response->json('token'))->toBeString();

    $token = $response->json('token');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('email', 'ada@example.test');
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->postJson('/api/register', [
        'name' => 'B', 'email' => 'taken@example.test', 'password' => 'password123',
    ])->assertStatus(422);
});

it('rejects registration with a too-short password', function () {
    $this->postJson('/api/register', [
        'name' => 'B', 'email' => 'b@example.test', 'password' => 'short',
    ])->assertStatus(422);
});

// login

it('logs in with correct credentials and returns a usable token', function () {
    $user = User::factory()->create(['email' => 'a@example.test', 'password' => bcrypt('correct-password')]);

    $response = $this->postJson('/api/login', [
        'email' => 'a@example.test', 'password' => 'correct-password',
    ])->assertOk();

    expect($response->json('token'))->toBeString()
        ->and($response->json('user.id'))->toBe($user->id);
});

it('rejects login with the wrong password, same message as a nonexistent email', function () {
    User::factory()->create(['email' => 'a@example.test', 'password' => bcrypt('correct-password')]);

    $wrongPassword = $this->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'nope'])
        ->assertStatus(422)->json('errors.email.0');
    $unknownEmail = $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'nope'])
        ->assertStatus(422)->json('errors.email.0');

    expect($wrongPassword)->toBe($unknownEmail);
});

// logout

it('logout revokes only the token the request authenticated with', function () {
    $user = User::factory()->create();
    $keepToken = $user->createToken('keep')->plainTextToken;
    $killToken = $user->createToken('kill')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$killToken}")->postJson('/api/logout')->assertStatus(204);

    // The auth guard caches the user between requests within one test.
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$killToken}")->getJson('/api/me')->assertStatus(401);
    $this->withHeader('Authorization', "Bearer {$keepToken}")->getJson('/api/me')->assertOk();
});

// protected routes

it('rejects an unauthenticated request to a protected route', function () {
    $this->getJson('/api/graph')->assertStatus(401);
});

it('returns 401, not a 500 redirect-to-login crash, for a plain request with no Accept header', function () {
    // getJson() sends an Accept header; a client without one must still get 401.
    $this->get('/api/graph')->assertStatus(401);
});

it('accepts a request authenticated via a real bearer token, not just Sanctum::actingAs', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/graph')
        ->assertOk();
});

// rate limits

it('returns 429 after 5 login attempts for one email from one IP, in any letter case', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'nope'])->assertStatus(422);
    }

    $this->postJson('/api/login', ['email' => 'A@Example.test', 'password' => 'nope'])->assertStatus(429);
});

it('does not let one IP lock an account for everyone else', function () {
    User::factory()->create(['email' => 'a@example.test', 'password' => bcrypt('correct-password')]);

    foreach (range(1, 6) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'nope']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
        ->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'correct-password'])
        ->assertOk();
});

it('returns 429 after 20 login attempts from one IP across different emails', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/login', ['email' => "u{$i}@example.test", 'password' => 'nope'])->assertStatus(422);
    }

    $this->postJson('/api/login', ['email' => 'u21@example.test', 'password' => 'nope'])->assertStatus(429);
});

it('returns 429 after 5 registrations from one IP', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/register', [
            'name' => 'B', 'email' => "r{$i}@example.test", 'password' => 'password123',
        ])->assertCreated();
    }

    $this->postJson('/api/register', [
        'name' => 'B', 'email' => 'r6@example.test', 'password' => 'password123',
    ])->assertStatus(429);
});

// token lifetime

it('defaults the token lifetime to 30 days', function () {
    expect(config('sanctum.expiration'))->toBe(43200);
});

it('rejects an expired token', function () {
    $token = User::factory()->create()->createToken('api')->plainTextToken;

    $this->travel(29)->days();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertOk();

    app('auth')->forgetGuards();
    $this->travel(2)->days();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(401);
});
