<?php

use App\Models\User;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Shared\Contracts\LlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Fakes\RecordingLlmProvider;

uses(RefreshDatabase::class);

function nodeWithBugRows(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $node = (new GraphService)->createNode([
        'user_id' => $user->id, 'type' => 'source', 'name' => 'A', 'dsl_config' => [],
        'columns_schema' => [
            'title' => ['name' => 'title', 'type' => 'string'],
            'category' => ['name' => 'category', 'type' => 'string'],
            'comments' => ['name' => 'comments', 'type' => 'number'],
        ],
    ]);
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['title' => 'A', 'category' => 'bug', 'comments' => 5]],
        ['row_key' => 'r2', 'data' => ['title' => 'B', 'category' => 'bug', 'comments' => 20]],
        ['row_key' => 'r3', 'data' => ['title' => 'C', 'category' => 'feature-request', 'comments' => 1]],
    ]));

    return [$user, $node->fresh()];
}

it('creates a view with filter/sort/limit criteria', function () {
    [$user, $node] = nodeWithBugRows();

    $response = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Top bugs',
        'filter' => ['category' => 'bug'],
        'sort_field' => 'comments',
        'sort_order' => 'desc',
        'limit_count' => 5,
    ])->assertCreated();

    expect($response->json('name'))->toBe('Top bugs')
        ->and($response->json('node_id'))->toBe($node->id);
});

it('lists a node\'s views, newest first', function () {
    [$user, $node] = nodeWithBugRows();
    $first = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'First']);
    $second = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Second']);

    $response = $this->getJson("/api/nodes/{$node->id}/views")->assertOk();

    expect($response->json('0.id'))->toBe($second->id)
        ->and($response->json('1.id'))->toBe($first->id);
});

it('rows applies the view\'s saved filter/sort/limit against the node\'s current data', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Top bugs',
        'filter' => ['category' => 'bug'], 'sort_field' => 'comments', 'sort_order' => 'desc', 'limit_count' => 1,
    ]);

    $response = $this->getJson("/api/views/{$view->id}/rows")->assertOk();

    expect($response->json('data'))->toBe([
        ['row_key' => 'r2', 'title' => 'B', 'category' => 'bug', 'comments' => 20],
    ]);
});

it('rows reflects the node\'s data live, without the view storing its own copy', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'All bugs',
        'filter' => ['category' => 'bug'],
    ]);

    expect($this->getJson("/api/views/{$view->id}/rows")->json('data'))->toHaveCount(2);

    // The view shows the node's new version at once.
    app(ResultStore::class)->store($node, new RowSet([
        ['row_key' => 'r1', 'data' => ['title' => 'A', 'category' => 'bug', 'comments' => 5]],
        ['row_key' => 'r2', 'data' => ['title' => 'B', 'category' => 'bug', 'comments' => 20]],
        ['row_key' => 'r3', 'data' => ['title' => 'C', 'category' => 'feature-request', 'comments' => 1]],
        ['row_key' => 'r4', 'data' => ['title' => 'D', 'category' => 'bug', 'comments' => 2]],
    ]));

    expect($this->getJson("/api/views/{$view->id}/rows")->json('data'))->toHaveCount(3);
});

it('creates a view with a range filter (cmp/value), not just equality', function () {
    [$user, $node] = nodeWithBugRows();

    $response = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Popular bugs',
        'filter' => ['comments' => ['cmp' => '>', 'value' => 4]],
    ])->assertCreated();

    // validate() would drop `value` if it had no rule.
    expect($response->json('filter'))->toBe(['comments' => ['cmp' => '>', 'value' => 4]]);
});

it('rows applies a range filter created through the real HTTP endpoint, not just Eloquent directly', function () {
    [$user, $node] = nodeWithBugRows();

    $view = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Popular bugs',
        'filter' => ['comments' => ['cmp' => '>', 'value' => 4]],
    ])->json();

    $response = $this->getJson("/api/views/{$view['id']}/rows")->assertOk();

    expect(collect($response->json('data'))->pluck('title')->sort()->values()->all())->toBe(['A', 'B']);
});

it('rows applies a saved range filter against real numeric comparison, not lexicographic', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Popular',
        'filter' => ['comments' => ['cmp' => '>', 'value' => 4]],
    ]);

    $response = $this->getJson("/api/views/{$view->id}/rows")->assertOk();

    expect(collect($response->json('data'))->pluck('title')->sort()->values()->all())->toBe(['A', 'B']);
});

it('rows rejects an unsupported cmp in a saved filter', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Bad',
        'filter' => ['comments' => ['cmp' => 'bogus', 'value' => 1]],
    ]);

    $this->getJson("/api/views/{$view->id}/rows")->assertStatus(422);
});

it('rows rejects a filter on an undeclared column', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Bad', 'filter' => ['bogus' => 'x'],
    ]);

    $this->getJson("/api/views/{$view->id}/rows")->assertStatus(422);
});

it('destroy removes the view without touching the node', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'X']);

    $this->deleteJson("/api/views/{$view->id}")->assertStatus(204);

    expect(NodeView::find($view->id))->toBeNull()
        ->and($node->fresh())->not->toBeNull();
});

it('rows/destroy return 404 for a view owned by another user', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'X']);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/views/{$view->id}/rows")->assertNotFound();
    $this->deleteJson("/api/views/{$view->id}")->assertNotFound();
    expect(NodeView::find($view->id))->not->toBeNull();
});

it('store/index return 404 for a node owned by another user', function () {
    [, $node] = nodeWithBugRows();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'X'])->assertNotFound();
    $this->getJson("/api/nodes/{$node->id}/views")->assertNotFound();
});

it('store with a phrase generates criteria via the LLM, persists the view, and returns rows in one call', function () {
    [, $node] = nodeWithBugRows();

    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'category', 'cmp' => '==', 'value' => 'bug']], 'sort_field' => 'comments', 'sort_order' => 'desc'],
    ]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Top bugs',
        'phrase' => 'самые обсуждаемые баги',
    ])->assertCreated();

    expect($response->json('name'))->toBe('Top bugs')
        ->and($response->json('filter'))->toEqual(['category' => ['cmp' => '==', 'value' => 'bug']])
        ->and($response->json('sort_field'))->toBe('comments')
        ->and($response->json('rows'))->toBe([
            ['row_key' => 'r2', 'title' => 'B', 'category' => 'bug', 'comments' => 20],
            ['row_key' => 'r1', 'title' => 'A', 'category' => 'bug', 'comments' => 5],
        ]);

    $saved = NodeView::where('node_id', $node->id)->where('name', 'Top bugs')->first();
    expect($saved)->not->toBeNull()
        ->and($saved->filter)->toEqual(['category' => ['cmp' => '==', 'value' => 'bug']]);
});

it('store with a phrase records the real tokens spent as a run_stats row', function () {
    [, $node] = nodeWithBugRows();

    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'category', 'cmp' => '==', 'value' => 'bug']]],
    ], tokensPerCall: 7);
    $this->app->instance(LlmProvider::class, $llm);

    $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Bugs',
        'phrase' => 'только баги',
    ])->assertCreated();

    $stat = RunStat::where('node_id', $node->id)->where('status', 'view_generation')->first();
    expect($stat)->not->toBeNull()
        ->and($stat->tokens_spent)->toBe(7);
});

it('store with a phrase returns 422 without persisting when the generated criteria never validates', function () {
    [, $node] = nodeWithBugRows();

    $stillInvalid = ['sort_order' => 'sideways'];
    $llm = new RecordingLlmProvider([$stillInvalid, $stillInvalid]);
    $this->app->instance(LlmProvider::class, $llm);

    $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Bad',
        'phrase' => 'что-то невнятное',
    ])->assertStatus(422);

    expect(NodeView::where('node_id', $node->id)->where('name', 'Bad')->exists())->toBeFalse();
});

it('store with a phrase retries when the generated criteria matches nothing on a node that has data', function () {
    [, $node] = nodeWithBugRows();

    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'category', 'cmp' => '==', 'value' => 'nonexistent-category']]],
        ['filter' => [['field' => 'category', 'cmp' => '==', 'value' => 'bug']]],
    ]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Bugs',
        'phrase' => 'баги',
    ])->assertCreated();

    expect($llm->callCount())->toBe(2)
        ->and($response->json('filter'))->toEqual(['category' => ['cmp' => '==', 'value' => 'bug']])
        ->and($response->json('rows'))->toHaveCount(2);
});

it('store with a phrase saves two conditions on one column as a range', function () {
    [, $node] = nodeWithBugRows();
    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'comments', 'cmp' => '>=', 'value' => '5'], ['field' => 'comments', 'cmp' => '<=', 'value' => '10']]],
    ]);
    $this->app->instance(LlmProvider::class, $llm);

    $response = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'Range', 'phrase' => 'комментариев от 5 до 10'])
        ->assertCreated();

    expect($llm->callCount())->toBe(1)
        ->and($response->json('filter.comments'))->toBe([['cmp' => '>=', 'value' => '5'], ['cmp' => '<=', 'value' => '10']])
        ->and($response->json('rows.*.title'))->toBe(['A'])
        ->and($this->getJson("/api/views/{$response->json('id')}/rows")->assertOk()->json('data.*.title'))->toBe(['A']);
});

// --- criteria checked when a view is created ---

/** 100 posts, ten for each of the users 1..10. */
function nodeWithPosts(): array
{
    [$user, $node] = nodeWithBugRows();
    $node->update(['columns_schema' => [
        'id' => ['name' => 'id', 'type' => 'number'],
        'userId' => ['name' => 'userId', 'type' => 'number'],
        'title' => ['name' => 'title', 'type' => 'string'],
    ]]);
    app(ResultStore::class)->store($node, new RowSet(array_map(fn (int $i) => [
        'row_key' => (string) $i, 'data' => ['id' => $i, 'userId' => intdiv($i - 1, 10) + 1, 'title' => "T{$i}"],
    ], range(1, 100))));

    return [$user, $node->fresh()];
}

it('saves a short filter in its full form and filters the rows by it', function (int|string $userId) {
    [, $node] = nodeWithPosts();

    $view = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'User 4', 'filter' => ['userId' => $userId]])
        ->assertCreated();

    expect($view->json('filter'))->toEqual(['userId' => ['cmp' => '==', 'value' => $userId]])
        ->and(NodeView::find($view->json('id'))->filter)->toEqual(['userId' => ['cmp' => '==', 'value' => $userId]]);

    $rows = $this->getJson("/api/views/{$view->json('id')}/rows")->assertOk();

    expect($rows->json('meta.total'))->toBe(10)
        ->and(array_unique($rows->json('data.*.userId')))->toBe([4]);
})->with(['a number' => 4, 'a number as text' => '4']);

it('saves the whole of a filter that mixes both forms', function () {
    [, $node] = nodeWithPosts();

    $view = $this->postJson("/api/nodes/{$node->id}/views", [
        'name' => 'Mixed',
        'filter' => ['userId' => 4, 'id' => ['cmp' => '>', 'value' => 35], 'title' => ['value' => 'T36']],
    ])->assertCreated();

    expect($view->json('filter'))->toEqual([
        'userId' => ['cmp' => '==', 'value' => 4],
        'id' => ['cmp' => '>', 'value' => 35],
        'title' => ['cmp' => '==', 'value' => 'T36'],
    ])
        ->and($this->getJson("/api/views/{$view->json('id')}/rows")->assertOk()->json('data.*.id'))->toBe([36]);
});

it('answers 422 to a view that could not be read, and saves nothing', function (array $criteria) {
    [, $node] = nodeWithPosts();

    $response = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'Bad', ...$criteria])->assertStatus(422);

    expect($response->json('error') ?? $response->json('message'))->toBeString()->not->toBeEmpty()
        ->and(NodeView::where('node_id', $node->id)->count())->toBe(0);
})->with([
    'unknown filter column' => [['filter' => ['nope' => 1]]],
    'a list as the filter' => [['filter' => [4]]],
    'unknown sort column' => [['sort_field' => 'nope']],
    'unknown unique column' => [['unique_field' => 'nope']],
    'short non-number on a number column' => [['filter' => ['userId' => 'abc']]],
    'full non-number on a number column' => [['filter' => ['userId' => ['cmp' => '>', 'value' => 'abc']]]],
    'unknown comparison' => [['filter' => ['userId' => ['cmp' => 'like', 'value' => 4]]]],
    'comparison that is not text' => [['filter' => ['userId' => ['cmp' => ['=='], 'value' => 4]]]],
    'comparison without a value' => [['filter' => ['title' => ['cmp' => '==']]]],
    'empty condition' => [['filter' => ['title' => []]]],
    'list of values' => [['filter' => ['title' => ['T1', 'T2']]]],
    'unknown key beside the value' => [['filter' => ['title' => ['value' => 'T1', 'op' => '!=']]]],
    'value that is a list' => [['filter' => ['title' => ['cmp' => '==', 'value' => ['T1']]]]],
]);

it('answers 422 to the same malformed filter when rows are read', function () {
    [, $node] = nodeWithPosts();

    $this->getJson("/api/nodes/{$node->id}/rows?filter[title][op]=T1")->assertStatus(422);
});

// --- pagination ---

function nodeWithManyRows(int $count): array
{
    [$user, $node] = nodeWithBugRows();
    app(ResultStore::class)->store($node, new RowSet(array_map(fn (int $i) => [
        'row_key' => "k{$i}", 'data' => ['title' => "T{$i}", 'category' => 'bug', 'comments' => $i],
    ], range(1, $count))));

    return [$user, $node->fresh()];
}

it('rows paginates with 25 per page by default and returns meta', function () {
    [$user, $node] = nodeWithManyRows(30);
    $view = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'All']);

    $response = $this->getJson("/api/views/{$view->id}/rows")->assertOk();

    expect($response->json('data'))->toHaveCount(25)
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'per_page' => 25, 'total' => 30, 'last_page' => 2]);
});

it('rows clamps per_page to 1..200', function () {
    [$user, $node] = nodeWithManyRows(210);
    $view = NodeView::create(['user_id' => $user->id, 'node_id' => $node->id, 'name' => 'All']);

    $over = $this->getJson("/api/views/{$view->id}/rows?per_page=1000")->assertOk();
    $under = $this->getJson("/api/views/{$view->id}/rows?per_page=0")->assertOk();

    expect($over->json('data'))->toHaveCount(200)
        ->and($over->json('meta.per_page'))->toBe(200)
        ->and($under->json('data'))->toHaveCount(1);
});

it('rows pages stay inside limit_count', function () {
    [$user, $node] = nodeWithManyRows(30);
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Top 7',
        'sort_field' => 'comments', 'sort_order' => 'desc', 'limit_count' => 7,
    ]);
    $page = fn (int $n) => $this->getJson("/api/views/{$view->id}/rows?per_page=5&page={$n}")->assertOk();

    expect($page(1)->json('meta'))->toBe(['current_page' => 1, 'per_page' => 5, 'total' => 7, 'last_page' => 2])
        ->and(array_column($page(1)->json('data'), 'comments'))->toBe([30, 29, 28, 27, 26])
        ->and(array_column($page(2)->json('data'), 'comments'))->toBe([25, 24])
        ->and($page(3)->json('data'))->toBe([]);
});

it('rows paginates a view with unique_field, counting distinct rows', function () {
    [$user, $node] = nodeWithBugRows();
    $view = NodeView::create([
        'user_id' => $user->id, 'node_id' => $node->id, 'name' => 'Categories', 'unique_field' => 'category',
    ]);

    $response = $this->getJson("/api/views/{$view->id}/rows?per_page=1&page=2")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('store with a phrase returns at most 200 rows', function (?int $limitCount) {
    [, $node] = nodeWithManyRows(210);
    $this->app->instance(LlmProvider::class, new RecordingLlmProvider([
        array_filter(['sort_field' => 'comments', 'limit_count' => $limitCount]),
    ]));

    $response = $this->postJson("/api/nodes/{$node->id}/views", ['name' => 'All', 'phrase' => 'все'])->assertCreated();

    expect($response->json('rows'))->toHaveCount(200)
        ->and($response->json('limit_count'))->toBe($limitCount);
})->with(['no limit_count' => null, 'limit_count above the cap' => 205]);
