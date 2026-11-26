<?php

use App\Modules\Intake\DslGenerator;
use App\Modules\Intake\Exceptions\DslValidationException;
use Tests\Support\Fakes\RecordingLlmProvider;

$parentSchemas = [
    [
        'node_id' => 'n1',
        'columns_schema' => [
            'col_price' => ['name' => 'price', 'type' => 'number'],
            'col_sku' => ['name' => 'sku', 'type' => 'string'],
        ],
    ],
];

$validStep = [['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 100]];

it('passes the system prompt, tier standard, and the dsl schema through to the provider', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([$validStep]);

    (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas);

    expect($llm->systemPrompts[0])->toContain('генератор декларативных конвейеров')
        ->and($llm->tiers[0])->toBe('standard')
        ->and($llm->jsonSchemas[0])->toHaveKey('definitions')
        ->and($llm->jsonSchemas[0]['definitions'])->toHaveKey('filter');
});

it('includes the user phrase and each parent node id + column schema in the user input', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([$validStep]);

    (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas);

    expect($llm->userInputs[0])->toContain('покажи товары дороже 100')
        ->and($llm->userInputs[0])->toContain('n1')
        ->and($llm->userInputs[0])->toContain('price: number')
        ->and($llm->userInputs[0])->toContain('sku: string');
});

it('resolves column names to col_id in the returned config', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([$validStep]);

    $result = (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas);

    expect($result[0]['field'])->toBe('col_price');
});

it('retries once with the validation error appended when the first response is invalid', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([
        [['op' => 'filter', 'field' => 'price']], // missing required cmp/value -> schema-invalid
        $validStep,
    ]);

    $result = (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas);

    expect($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('не прошёл валидацию')
        ->and($result[0]['field'])->toBe('col_price');
});

it('retries once when the first response references an unknown column', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([
        [['op' => 'filter', 'field' => 'bogus_column', 'cmp' => '>', 'value' => 1]],
        $validStep,
    ]);

    $result = (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas);

    expect($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('bogus_column')
        ->and($result[0]['field'])->toBe('col_price');
});

it('propagates the validation error to the caller after a second failure', function () use ($parentSchemas) {
    $stillInvalid = [['op' => 'filter', 'field' => 'price']]; // always missing cmp/value
    $llm = new RecordingLlmProvider([$stillInvalid, $stillInvalid]);

    expect(fn () => (new DslGenerator($llm))->generate('покажи товары дороже 100', $parentSchemas))
        ->toThrow(DslValidationException::class);

    expect($llm->callCount())->toBe(2);
});

it('lastTokensUsed reports the real spend of a single successful call', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([$validStep], tokensPerCall: 42);
    $generator = new DslGenerator($llm);

    $generator->generate('покажи товары дороже 100', $parentSchemas);

    expect($generator->lastTokensUsed())->toBe(42);
});

it('lastTokensUsed sums both calls when a retry happens', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([
        [['op' => 'filter', 'field' => 'price']], // invalid -> triggers the retry
        $validStep,
    ], tokensPerCall: 42);
    $generator = new DslGenerator($llm);

    $generator->generate('покажи товары дороже 100', $parentSchemas);

    expect($generator->lastTokensUsed())->toBe(84);
});

it('lastTokensUsed still reflects both real calls even when the second attempt also fails', function () use ($parentSchemas) {
    $stillInvalid = [['op' => 'filter', 'field' => 'price']];
    $llm = new RecordingLlmProvider([$stillInvalid, $stillInvalid], tokensPerCall: 42);
    $generator = new DslGenerator($llm);

    expect(fn () => $generator->generate('покажи товары дороже 100', $parentSchemas))
        ->toThrow(DslValidationException::class);

    expect($generator->lastTokensUsed())->toBe(84);
});

it('lastTokensUsed resets to just this call\'s spend, not a running total across calls', function () use ($parentSchemas, $validStep) {
    $llm = new RecordingLlmProvider([$validStep], tokensPerCall: 42);
    $generator = new DslGenerator($llm);

    $generator->generate('первая фраза', $parentSchemas);
    $generator->generate('вторая фраза', $parentSchemas);

    expect($generator->lastTokensUsed())->toBe(42); // not 84
});
