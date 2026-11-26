<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\WebhookIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function webhookNode(GraphService $graph, User $user, ?string $secret = 'shh'): Node
{
    return $graph->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [['op' => 'webhook_source'], ['op' => 'extract', 'path' => '$.items[*]']],
        'trigger' => 'webhook',
        'webhook_secret' => $secret,
    ]);
}

it('verifySignature accepts a correct HMAC-SHA256 signature over the raw body', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user);
    $body = '{"items":[{"sku":"A1"}]}';
    $signature = hash_hmac('sha256', $body, 'shh');

    expect((new WebhookIngestService)->verifySignature($node, $body, $signature))->toBeTrue();
});

it('verifySignature rejects a wrong signature', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user);
    $body = '{"items":[]}';

    expect((new WebhookIngestService)->verifySignature($node, $body, 'not-the-right-signature'))->toBeFalse();
});

it('verifySignature rejects a signature computed over different bytes than the body', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user);
    $signature = hash_hmac('sha256', '{"items":[]}', 'shh');

    // A valid signature, but for a different body.
    expect((new WebhookIngestService)->verifySignature($node, '{"items":[{"tampered":true}]}', $signature))
        ->toBeFalse();
});

it('verifySignature rejects when the node has no webhook_secret configured', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user, secret: null);
    $body = '{}';
    $signature = hash_hmac('sha256', $body, 'anything');

    expect((new WebhookIngestService)->verifySignature($node, $body, $signature))->toBeFalse();
});

it('verifySignature rejects a null or empty signature', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user);

    $service = new WebhookIngestService;

    expect($service->verifySignature($node, '{}', null))->toBeFalse()
        ->and($service->verifySignature($node, '{}', ''))->toBeFalse();
});

it('ingest stores the payload and marks the node stale', function () {
    $user = User::factory()->create();
    $node = webhookNode(new GraphService, $user);
    $node->update(['status' => 'fresh']);

    (new WebhookIngestService)->ingest($node, ['items' => [['sku' => 'A1']]]);

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->webhook_payload)->toBe(['items' => [['sku' => 'A1']]]);
});
