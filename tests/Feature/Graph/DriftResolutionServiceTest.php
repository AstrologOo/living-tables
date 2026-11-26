<?php

use App\Models\User;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\DriftResolutionService;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function driftedNode(GraphService $graph, User $user, array $columnsSchema): Node
{
    return tap($graph->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'columns_schema' => $columnsSchema,
    ]), fn ($node) => $node->update(['status' => 'schema_drift']));
}

it('confirmFieldsReturned clears schema_drift back to stale without touching columns_schema', function () {
    $user = User::factory()->create();
    $schema = ['col_price' => ['name' => 'price', 'type' => 'number']];
    $node = driftedNode(new GraphService, $user, $schema);

    (new DriftResolutionService)->confirmFieldsReturned($node);

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->columns_schema)->toBe($schema);
});

it('confirmFieldsReturned refuses a node that is not currently schema_drift', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
    ]);

    expect(fn () => (new DriftResolutionService)->confirmFieldsReturned($node))
        ->toThrow(InvalidArgumentException::class);
});

it('removeColumn drops the col_id from columns_schema and returns the node to stale', function () {
    $user = User::factory()->create();
    $node = driftedNode(new GraphService, $user, [
        'col_sku' => ['name' => 'sku', 'type' => 'string'],
        'col_price' => ['name' => 'price', 'type' => 'number'],
    ]);

    (new DriftResolutionService)->removeColumn($node, 'col_price');

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->columns_schema)->toBe(['col_sku' => ['name' => 'sku', 'type' => 'string']]);
});

it('removeColumn refuses a node that is not currently schema_drift', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['col_price' => ['name' => 'price', 'type' => 'number']],
    ]);

    expect(fn () => (new DriftResolutionService)->removeColumn($node, 'col_price'))
        ->toThrow(InvalidArgumentException::class);
});

it('removeColumn refuses an unknown col_id', function () {
    $user = User::factory()->create();
    $node = driftedNode(new GraphService, $user, ['col_sku' => ['name' => 'sku', 'type' => 'string']]);

    expect(fn () => (new DriftResolutionService)->removeColumn($node, 'col_bogus'))
        ->toThrow(InvalidArgumentException::class);
});

it('resets the failure counter and the retry delay', function (string $resolution) {
    $node = (new GraphService)->createNode([
        'user_id' => User::factory()->create()->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => ['price' => ['name' => 'price', 'type' => 'number']],
    ]);
    $node->update(['status' => 'schema_drift', 'consecutive_failures' => 3, 'retry_at' => now()->addHour()]);
    $service = new DriftResolutionService;

    $resolution === 'confirm'
        ? $service->confirmFieldsReturned($node->fresh())
        : $service->removeColumn($node->fresh(), 'price');

    expect($node->fresh()->status)->toBe('stale')
        ->and($node->fresh()->consecutive_failures)->toBe(0)
        ->and($node->fresh()->retry_at)->toBeNull();
})->with(['confirm', 'remove']);
