<?php

use App\Console\Support\NodeRecalculator;
use App\Models\User;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Providers\GraphLlmCacheRepository;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Shared\Adapters\NullDocumentParser;
use App\Modules\Shared\Adapters\NullLlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A fresh node with one row of data. */
function nodeWithSecret(User $user, string $name, array $parentIds = []): Node
{
    $node = (new GraphService)->createNode(['user_id' => $user->id, 'type' => 'source', 'name' => $name, 'dsl_config' => []], $parentIds);
    $node->update(['status' => 'processing']);
    (new ResultStore)->store($node, new RowSet([
        ['row_key' => '1', 'row_hash' => hash('sha256', $name), 'data' => ['id' => 1, 'secret' => $name]],
    ]));

    return $node->fresh();
}

/** Runs $node's saved pipeline the way the worker does. */
function runSavedConfig(Node $node): Node
{
    $node->update(['status' => 'stale']);
    $ctx = new ExecutionContext(new GraphParentDataProvider, new NullLlmProvider, new NullDocumentParser, new GraphLlmCacheRepository, new GraphUsageLimiter, new GraphRowStore);
    $scheduler = new SchedulerService;
    $recalculator = new NodeRecalculator($scheduler, new ResultStore, new PipelineRunner, Mockery::mock(NodeEventPublisher::class)->shouldIgnoreMissing());
    $recalculator->claimAndProcess($ctx);

    return $node->fresh();
}

/** @return array<int, array<string, mixed>> a pipeline that reads $targetId through `parent` or `merge` */
function pipelineReading(string $op, string $targetId, string $ownParentId): array
{
    return $op === 'parent'
        ? [['op' => 'parent', 'node' => $targetId]]
        : [['op' => 'parent', 'node' => $ownParentId], ['op' => 'merge', 'with' => $targetId, 'on' => 'id']];
}

/** @return array{victim: Node, own: Node, reader: Node, intruder: User} */
function intruderAndVictim(): array
{
    $intruder = User::factory()->create();
    $victim = nodeWithSecret(User::factory()->create(), 'victim');
    $own = nodeWithSecret($intruder, 'own');
    $reader = (new GraphService)->createNode(['user_id' => $intruder->id, 'type' => 'transform', 'name' => 'reader', 'dsl_config' => []], [$own->id]);
    $reader->update(['status' => 'fresh']);

    return ['victim' => $victim, 'own' => $own, 'reader' => $reader->fresh(), 'intruder' => $intruder];
}

function secretsReadBy(Node $node): array
{
    return DB::table('node_rows')->where('node_id', $node->id)->pluck('data')
        ->map(fn (string $data) => json_decode($data, true)['secret'])->all();
}

dataset('reading steps', ['parent', 'merge']);

it('apply refuses a pipeline that reads another user\'s node', function (string $op) {
    ['victim' => $victim, 'own' => $own, 'reader' => $reader, 'intruder' => $intruder] = intruderAndVictim();
    Sanctum::actingAs($intruder);

    $this->postJson("/api/nodes/{$reader->id}/apply", ['dsl_config' => pipelineReading($op, $victim->id, $own->id)])
        ->assertStatus(422)
        ->assertJsonStructure(['errors']);

    expect($reader->fresh()->only(['dsl_config', 'status']))->toBe(['dsl_config' => [], 'status' => 'fresh']);
})->with('reading steps');

it('apply refuses a node of one\'s own that is not a declared parent', function (string $op) {
    ['own' => $own, 'reader' => $reader, 'intruder' => $intruder] = intruderAndVictim();
    $other = nodeWithSecret($intruder, 'unrelated');
    Sanctum::actingAs($intruder);

    $this->postJson("/api/nodes/{$reader->id}/apply", ['dsl_config' => pipelineReading($op, $other->id, $own->id)])
        ->assertStatus(422);
})->with('reading steps');

it('rollback refuses a saved config that reads another user\'s node', function () {
    ['victim' => $victim, 'reader' => $reader, 'intruder' => $intruder] = intruderAndVictim();
    $version = NodeConfigVersion::create([
        'user_id' => $intruder->id, 'node_id' => $reader->id, 'dsl_config' => [['op' => 'parent', 'node' => $victim->id]], 'config_hash' => 'saved-before-the-check',
    ]);
    Sanctum::actingAs($intruder);

    $this->postJson("/api/nodes/{$reader->id}/config-versions/{$version->id}/rollback")->assertStatus(422);

    expect($reader->fresh()->dsl_config)->toBe([]);
});

it('the worker does not read another user\'s node for a saved config', function (string $op) {
    ['victim' => $victim, 'own' => $own, 'reader' => $reader] = intruderAndVictim();
    $reader->update(['dsl_config' => pipelineReading($op, $victim->id, $own->id)]);

    $reader = runSavedConfig($reader);

    expect($reader->status)->toBe('failed')
        ->and($reader->current_version_id)->toBeNull()
        ->and(secretsReadBy($reader))->toBe([]);
})->with('reading steps');

it('the worker does not read another user\'s node even through an edge', function (string $op) {
    ['victim' => $victim, 'own' => $own, 'reader' => $reader, 'intruder' => $intruder] = intruderAndVictim();
    DB::table('edges')->insert(['id' => (string) Str::orderedUuid(), 'user_id' => $intruder->id, 'node_id' => $reader->id, 'depends_on_id' => $victim->id]);
    $reader->update(['dsl_config' => pipelineReading($op, $victim->id, $own->id)]);

    $reader = runSavedConfig($reader);

    expect($reader->status)->toBe('failed')->and(secretsReadBy($reader))->toBe([]);
})->with('reading steps');

it('the worker tells the owner which reference was refused', function () {
    ['victim' => $victim, 'reader' => $reader, 'intruder' => $intruder] = intruderAndVictim();
    $reader->update(['dsl_config' => [['op' => 'parent', 'node' => $victim->id]]]);

    runSavedConfig($reader);

    expect(DB::table('node_notifications')->where('user_id', $intruder->id)->value('payload'))
        ->toContain('declared parents')->not->toContain('victim');
});

it('answers 404, not an error, for a parent id that does not exist', function (string $parentId) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/nodes', ['type' => 'transform', 'name' => 'x', 'parent_ids' => [$parentId]])->assertStatus(404);

    expect(Node::count())->toBe(0);
})->with(['00000000-0000-7000-8000-000000000000', 'not-a-uuid']);
