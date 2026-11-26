<?php

use App\Models\User;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A node of a signed-in user, with a schema a phrase can refer to. */
function nodeAwaitingAPhrase(): string
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => []]);
    $node->update(['columns_schema' => ['id' => ['name' => 'id', 'type' => 'number']]]);

    return $node->id;
}

it('answers a refused generation with a plain message, not an error 500', function (string $endpoint, array $body, string $apiKey) {
    config(['llm.gemini.api_key' => $apiKey]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'Your billing account has an outstanding balance']], 403)]);
    $node = nodeAwaitingAPhrase();

    $response = $this->postJson("/api/nodes/{$node}/{$endpoint}", $body);

    $response->assertStatus(503)->assertExactJson(['error' => 'сервис генерации недоступен']);
    expect(NodeView::count())->toBe(0);
})->with([
    'node from a phrase' => ['preview', ['phrase' => 'all rows']],
    'view from a phrase' => ['views', ['name' => 'v', 'phrase' => 'all rows']],
])->with(['with a key' => 'test-key', 'without a key' => '']);
