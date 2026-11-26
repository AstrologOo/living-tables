<?php

use App\Modules\Shared\Adapters\Llm\GeminiAdapter;
use App\Modules\Shared\Exceptions\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function fakeGeminiResponse(array $steps, int $tokens = 42): array
{
    return [
        'candidates' => [
            ['content' => ['parts' => [['text' => json_encode($steps)]]]],
        ],
        'usageMetadata' => ['totalTokenCount' => $tokens],
    ];
}

it('exposes the full usageMetadata breakdown from the last call', function () {
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '[]']]]]],
        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 125],
    ])]);

    $adapter = app(GeminiAdapter::class);

    expect($adapter->lastUsageMetadata())->toBe([]);

    $adapter->generate('s', 'u', [], 'standard');

    expect($adapter->lastUsageMetadata())->toBe([
        'promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 125,
    ]);
});

it('calls the model mapped to the given tier and returns the decoded steps', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([['op' => 'limit', 'count' => 5]]))]);

    $adapter = app(GeminiAdapter::class);
    $result = $adapter->generate('system', 'user input', ['type' => 'array'], 'standard');

    expect($result)->toBe([['op' => 'limit', 'count' => 5]]);

    $request = Http::recorded()[0][0];
    expect($request->url())->toContain(config('llm.gemini.models.standard').':generateContent')
        ->and($request->url())->not->toContain('key=') // must not leak into the URL (and so into error text/logs)
        ->and($request->header('x-goog-api-key')[0])->toBe(config('llm.gemini.api_key'))
        ->and($request->data()['systemInstruction']['parts'][0]['text'])->toBe('system')
        ->and($request->data()['contents'][0]['parts'][0]['text'])->toBe('user input')
        ->and($request->data()['generationConfig']['responseMimeType'])->toBe('application/json')
        ->and($request->data()['generationConfig'])->not->toHaveKey('thinkingConfig');
});

it('sends a thinking level only when one is configured', function () {
    config(['llm.gemini.thinking_level' => 'low']);
    Http::fake(['*' => Http::response(fakeGeminiResponse([]))]);

    app(GeminiAdapter::class)->generate('s', 'u', [], 'standard');

    expect(Http::recorded()[0][0]->data()['generationConfig']['thinkingConfig'])->toBe(['thinkingLevel' => 'low']);
});

it('pins concrete model versions by default', function () {
    $env = file_get_contents(base_path('.env.example'));

    foreach (['cheap', 'standard', 'smart'] as $tier) {
        $model = (require config_path('llm.php'))['gemini']['models'][$tier];

        expect($model)->not->toContain('latest')
            ->and($env)->toContain('GEMINI_MODEL_'.strtoupper($tier)."={$model}\n");
    }
});

it('reports any provider failure as the provider being unavailable', function (Closure $fake) {
    Http::fake(['*' => $fake()]);

    expect(fn () => app(GeminiAdapter::class)->generate('s', 'u', [], 'standard'))
        ->toThrow(LlmUnavailableException::class);
})->with([
    'refused' => [fn () => fn () => Http::response(['error' => ['message' => 'billing']], 403)],
    'rejected' => [fn () => fn () => Http::response(['error' => ['message' => 'bad parameter']], 400)],
    'unreachable' => [fn () => fn () => throw new ConnectionException('down')],
    'no text' => [fn () => fn () => Http::response(['candidates' => []])],
    'not json' => [fn () => fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'sorry']]]]]])],
]);

it('uses a different model for a different tier', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([]))]);

    (app(GeminiAdapter::class))->generate('s', 'u', [], 'cheap');

    expect(Http::recorded()[0][0]->url())->toContain(config('llm.gemini.models.cheap').':generateContent');
});

it('exposes the token count from usageMetadata', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([], tokens: 777))]);

    $adapter = app(GeminiAdapter::class);
    $adapter->generate('s', 'u', [], 'standard');

    expect($adapter->lastTokensUsed())->toBe(777);
});

it('lowercases enum-bearing fields defensively', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'FILTER', 'field' => 'price', 'cmp' => 'GREATER', 'value' => 1],
        ['op' => 'Sort', 'field' => 'price', 'order' => 'DESC'],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0]['op'])->toBe('filter')
        ->and($result[0]['cmp'])->toBe('greater')
        ->and($result[1]['op'])->toBe('sort')
        ->and($result[1]['order'])->toBe('desc');
});

it('throws for an unknown tier without making a request', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([]))]);

    expect(fn () => (app(GeminiAdapter::class))->generate('s', 'u', [], 'ultra'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('throws when the response has no candidate text', function () {
    Http::fake(['*' => Http::response(['candidates' => []])]);

    expect(fn () => (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard'))
        ->toThrow(RuntimeException::class);
});

it('throws when the candidate text is not a JSON array', function () {
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'not json']]]]],
    ])]);

    expect(fn () => (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard'))
        ->toThrow(RuntimeException::class);
});

it('does not support batching', function () {
    expect((app(GeminiAdapter::class))->supportsBatch())->toBeFalse();
});

it('sends an anyOf branch per op, each restricted to its own fields', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([]))]);

    (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    $schema = Http::recorded()[0][0]->data()['generationConfig']['responseSchema'];
    $branches = $schema['items']['anyOf'];

    expect($branches)->toHaveCount(11);

    $byOp = collect($branches)->keyBy(fn (array $branch) => $branch['properties']['op']['enum'][0]);

    expect($byOp['filter']['required'])->toBe(['op', 'field', 'cmp', 'value'])
        ->and($byOp['filter'])->not->toHaveKey('additionalProperties') // rejected by the live API inside anyOf
        ->and(array_keys($byOp['filter']['properties']))->toBe(['op', 'field', 'cmp', 'value'])
        ->and($byOp['merge']['required'])->toBe(['op', 'with', 'on'])
        ->and(array_keys($byOp['merge']['properties']))->toBe(['op', 'with', 'on', 'how']);

    // The `filter` branch doesn't offer `merge`'s "with".
    expect($byOp['filter']['properties'])->not->toHaveKey('with');

    // The pipeline schema is hand-written and carries no explicit ordering.
    expect(json_encode($schema))->not->toContain('propertyOrdering');

    expect($byOp['llm']['required'])->toBe(['op', 'instruction', 'input_fields', 'output_field', 'model_tier'])
        ->and($byOp['llm']['properties']['input_fields'])->toBe(['type' => 'ARRAY', 'items' => ['type' => 'STRING']])
        ->and($byOp['llm']['properties']['model_tier']['enum'])->toBe(['cheap', 'standard', 'smart']);
});

it('lowercases model_tier defensively, like the other enum-bearing fields', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['a'], 'output_field' => 'b', 'model_tier' => 'STANDARD'],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0]['model_tier'])->toBe('standard');
});

it('strips fields foreign to the step\'s own op even if the model produced them', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => '100', 'with' => 'stray-id'],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => '100']);
});

it('leaves a step with an unrecognized op untouched by the sanitizer', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'bogus_op', 'whatever' => 1],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'bogus_op', 'whatever' => 1]);
});

it('repairs map.fields given as a plain list of column names into an identity object', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'map', 'fields' => ['sku', 'name']],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'map', 'fields' => ['sku' => 'sku', 'name' => 'name']]);
});

it('leaves a well-formed map.fields object untouched', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'map', 'fields' => ['total' => ['expr' => 'price * qty']]],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'map', 'fields' => ['total' => ['expr' => 'price * qty']]]);
});

it('reassembles map.fields from the Gemini entry-array shape into the canonical object', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'map', 'fields' => [
            ['new_col' => 'sku', 'source_col' => 'артикул'],
            ['new_col' => 'total', 'expr' => 'price * qty'],
            ['new_col' => 'name'],
        ]],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'map', 'fields' => [
        'sku' => 'артикул',
        'total' => ['expr' => 'price * qty'],
        'name' => 'name',
    ]]);
});

it('leaves an empty map.fields as-is instead of miscombining it', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['op' => 'map', 'fields' => []],
    ]))]);

    $result = (app(GeminiAdapter::class))->generate('s', 'u', [], 'standard');

    expect($result[0])->toBe(['op' => 'map', 'fields' => []]);
});

it('translates a non-DSL schema generically and returns the response unmodified by DSL normalization', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['id' => 0, 'value' => 'positive'],
    ]))]);

    $jsonSchema = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'value' => ['type' => 'string'],
            ],
            'required' => ['id', 'value'],
        ],
    ];

    $result = (app(GeminiAdapter::class))->generate('s', 'u', $jsonSchema, 'standard');

    expect($result)->toBe([['id' => 0, 'value' => 'positive']]);

    $sentSchema = Http::recorded()[0][0]->data()['generationConfig']['responseSchema'];

    expect($sentSchema)->toBe([
        'type' => 'ARRAY',
        'items' => [
            'type' => 'OBJECT',
            'properties' => [
                'id' => ['type' => 'INTEGER'],
                'value' => ['type' => 'STRING'],
            ],
            'propertyOrdering' => ['id', 'value'],
            'required' => ['id', 'value'],
        ],
    ]);
});

it('asks for the properties in the order the view criteria schema declares them', function () {
    Http::fake(['*' => Http::response(fakeGeminiResponse([]))]);

    $jsonSchema = json_decode(file_get_contents(app_path('Modules/Intake/schema/view-criteria.schema.json')), associative: true);

    app(GeminiAdapter::class)->generate('s', 'u', $jsonSchema, 'cheap');

    $sentSchema = Http::recorded()[0][0]->data()['generationConfig']['responseSchema'];

    expect($sentSchema['propertyOrdering'])->toBe(array_keys($jsonSchema['properties']))
        ->and($sentSchema['propertyOrdering'])->toBe(array_keys($sentSchema['properties']))
        ->and($sentSchema['properties']['filter']['items']['propertyOrdering'])
        ->toBe(array_keys($jsonSchema['properties']['filter']['items']['properties']))
        ->and($sentSchema['properties']['limit_count'])->not->toHaveKey('propertyOrdering');
});

it('does not apply DSL-only normalization (e.g. enum lowercasing) to a non-DSL response', function () {
    // `op` would be lowercased if normalize() ran on this path.
    Http::fake(['*' => Http::response(fakeGeminiResponse([
        ['id' => 0, 'op' => 'KEEP-ME'],
    ]))]);

    $jsonSchema = ['type' => 'array', 'items' => ['type' => 'object']];

    $result = (app(GeminiAdapter::class))->generate('s', 'u', $jsonSchema, 'standard');

    expect($result)->toBe([['id' => 0, 'op' => 'KEEP-ME']]);
});
