<?php

use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
use App\Modules\Intake\ViewCriteriaGenerator;
use Tests\Support\Fakes\RecordingLlmProvider;

$columnsSchema = [
    'col_category' => ['name' => 'category', 'type' => 'string'],
    'col_comments' => ['name' => 'comments', 'type' => 'number'],
];

$validCriteria = [
    'filter' => [['field' => 'category', 'cmp' => '==', 'value' => 'bug']],
    'sort_field' => 'comments',
    'sort_order' => 'desc',
];

it('passes the system prompt, tier cheap, and the criteria schema through to the provider', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([$validCriteria]);

    (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema);

    expect($llm->systemPrompts[0])->toContain('генератор критериев')
        ->and($llm->tiers[0])->toBe('cheap')
        ->and($llm->jsonSchemas[0])->toHaveKey('properties')
        ->and($llm->jsonSchemas[0]['properties'])->toHaveKey('filter');
});

it('includes the phrase and column names/types in the user input', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([$validCriteria]);

    (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema);

    expect($llm->userInputs[0])->toContain('топ багов')
        ->and($llm->userInputs[0])->toContain('category: string')
        ->and($llm->userInputs[0])->toContain('comments: number');
});

it('resolves filter/sort_field column names to col_id in the returned criteria', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([$validCriteria]);

    $result = (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema);

    expect($result['filter'])->toBe(['col_category' => 'bug'])
        ->and($result['sort_field'])->toBe('col_comments');
});

it('keeps a non-equality cmp as {cmp, value} in the resolved filter, not just a bare value', function () use ($columnsSchema) {
    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'comments', 'cmp' => '>', 'value' => '10']]],
    ]);

    $result = (new ViewCriteriaGenerator($llm))->generate('больше 10 комментариев', $columnsSchema);

    expect($result['filter'])->toBe(['col_comments' => ['cmp' => '>', 'value' => '10']]);
});

it('retries once with the validation error appended when the first response is schema-invalid', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([
        ['sort_order' => 'sideways'], // not in enum -> schema-invalid
        $validCriteria,
    ]);

    $result = (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema);

    expect($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('не прошёл валидацию')
        ->and($result['sort_field'])->toBe('col_comments');
});

it('retries once when the first response references an unknown column', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([
        ['filter' => [['field' => 'bogus_column', 'cmp' => '==', 'value' => 'x']]],
        $validCriteria,
    ]);

    $result = (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema);

    expect($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('bogus_column')
        ->and($result['filter'])->toBe(['col_category' => 'bug']);
});

it('propagates the validation error to the caller after a second failure', function () use ($columnsSchema) {
    $stillInvalid = ['sort_order' => 'sideways'];
    $llm = new RecordingLlmProvider([$stillInvalid, $stillInvalid]);

    expect(fn () => (new ViewCriteriaGenerator($llm))->generate('топ багов', $columnsSchema))
        ->toThrow(ViewCriteriaValidationException::class);

    expect($llm->callCount())->toBe(2);
});

it('accepts an empty object as valid criteria ("show everything as-is")', function () use ($columnsSchema) {
    $llm = new RecordingLlmProvider([[]]);

    $result = (new ViewCriteriaGenerator($llm))->generate('покажи всё как есть', $columnsSchema);

    expect($result)->toBe([]);
});

it('lastTokensUsed sums both calls when a retry happens', function () use ($columnsSchema, $validCriteria) {
    $llm = new RecordingLlmProvider([
        ['sort_order' => 'sideways'],
        $validCriteria,
    ], tokensPerCall: 10);
    $generator = new ViewCriteriaGenerator($llm);

    $generator->generate('топ багов', $columnsSchema);

    expect($generator->lastTokensUsed())->toBe(20);
});
