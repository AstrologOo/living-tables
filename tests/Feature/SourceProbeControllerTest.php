<?php

use App\Models\User;
use App\Modules\Shared\Support\SafeUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Fakes\StubDnsSafeUrlGuard;

uses(RefreshDatabase::class);

it('probes a URL and returns the detected format/pagination/sample', function () {
    Sanctum::actingAs(User::factory()->create());
    Http::fake(['https://api.test/items' => Http::response(json_encode(['items' => [['id' => 1]]]), 200, [
        'Link' => '<https://api.test/items?page=2>; rel="next"',
    ])]);

    $response = $this->postJson('/api/sources/probe', ['url' => 'https://api.test/items'])->assertOk();

    expect($response->json('format'))->toBe('json')
        ->and($response->json('pagination.strategy'))->toBe('link_header')
        ->and($response->json('sample'))->toBe(['items' => [['id' => 1]]]);
});

it('requires a url', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/sources/probe', [])->assertStatus(422);
});

it('returns 422, not 500, when the URL is blocked as SSRF', function () {
    Sanctum::actingAs(User::factory()->create());

    // TestCase binds a permissive guard; this test needs the real one.
    $this->app->instance(SafeUrlGuard::class, new SafeUrlGuard);

    $this->postJson('/api/sources/probe', ['url' => 'http://169.254.169.254/'])->assertStatus(422);
});

it('returns 422, not 500, when the source responds with a server error', function () {
    Sanctum::actingAs(User::factory()->create());
    Http::fake(['https://api.test/broken' => Http::response('', 500)]);

    $this->postJson('/api/sources/probe', ['url' => 'https://api.test/broken'])->assertStatus(422);
});

it('requires authentication', function () {
    $this->postJson('/api/sources/probe', ['url' => 'https://api.test/items'])->assertStatus(401);
});

it('does not leak IPs or hostnames in the error, and logs the details', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->app->instance(SafeUrlGuard::class, new StubDnsSafeUrlGuard(['internal.example' => ['10.1.2.3']]));
    Log::spy();

    $response = $this->postJson('/api/sources/probe', ['url' => 'http://internal.example/secret'])->assertStatus(422);

    expect($response->json('error'))->toBe('адрес источника запрещён')
        ->and($response->json('error'))->not->toContain('10.1.2.3')
        ->and($response->json('error'))->not->toContain('internal.example')
        ->and($response->json('error'))->not->toMatch('/\d+\.\d+\.\d+\.\d+/');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, '10.1.2.3'))
        ->once();
});

it('says the address was not found when the host does not resolve', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->app->instance(SafeUrlGuard::class, new StubDnsSafeUrlGuard([]));

    $this->postJson('/api/sources/probe', ['url' => 'http://nowhere.example/'])
        ->assertStatus(422)
        ->assertExactJson(['error' => 'не удалось найти адрес источника']);
});
