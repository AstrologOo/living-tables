<?php

use App\Models\User;
use App\Modules\Execution\RowBatch;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A child that declares $parent, so it may read it. */
function payloadReader(Node $parent): Node
{
    return (new GraphService)->createNode(['user_id' => $parent->user_id, 'type' => 'transform', 'name' => 'reader', 'dsl_config' => []], [$parent->id]);
}

it('reads the current version of a node as a Payload', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
        'columns_schema' => ['col_a' => ['name' => 'A', 'type' => 'string']],
    ]);

    (new ResultStore)->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['col_a' => 'x']],
        ['row_key' => '2', 'data' => ['col_a' => 'y']],
    ]));

    $payload = (new GraphParentDataProvider)->latestPayload($node->id, payloadReader($node)->id);

    expect($payload)->toBeInstanceOf(RowBatch::class)
        ->and($payload->count())->toBe(2)
        ->and($payload->columnsSchema())->toBe(['col_a' => ['name' => 'A', 'type' => 'string']])
        ->and($payload->rows())->toBe([
            ['row_key' => '1', 'data' => ['col_a' => 'x']],
            ['row_key' => '2', 'data' => ['col_a' => 'y']],
        ]);
});

it('returns an empty Payload for a node with no version yet', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
    ]);

    $payload = (new GraphParentDataProvider)->latestPayload($node->id, payloadReader($node)->id);

    expect($payload->count())->toBe(0)
        ->and($payload->rows())->toBe([]);
});

it('reflects the latest version after a row changes', function () {
    $user = User::factory()->create();
    $node = (new GraphService)->createNode([
        'user_id' => $user->id,
        'type' => 'source',
        'name' => 'A',
        'dsl_config' => [],
    ]);
    $store = new ResultStore;

    $store->store($node, new RowSet([
        ['row_key' => '1', 'data' => ['col_a' => 'x']],
    ]));
    $store->store($node->fresh(), new RowSet([
        ['row_key' => '1', 'data' => ['col_a' => 'updated']],
    ]));

    $payload = (new GraphParentDataProvider)->latestPayload($node->id, payloadReader($node)->id);

    expect($payload->rows())->toBe([
        ['row_key' => '1', 'data' => ['col_a' => 'updated']],
    ]);
});
