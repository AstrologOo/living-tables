<?php

use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\Operations\LlmOperation;
use App\Modules\Execution\RowBatch;
use App\Modules\Shared\Contracts\LlmProvider;
use Illuminate\Support\Facades\Log;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeUsageLimiter;
use Tests\Support\Fakes\RecordingLlmProvider;

function llmConfig(array $overrides = []): array
{
    return [...[
        'instruction' => 'Classify sentiment',
        'input_fields' => ['col_text'],
        'output_field' => 'col_sentiment',
        'model_tier' => 'standard',
    ], ...$overrides];
}

it('returns the input untouched and makes no call when there are no rows', function () {
    $llm = new RecordingLlmProvider;
    $ctx = executionContext(llmProvider: $llm);

    $result = (new LlmOperation)->apply(RowBatch::fromRows([]), llmConfig(), $ctx);

    expect($result->rows())->toBe([])
        ->and($llm->callCount())->toBe(0);
});

it('throws LimitExceededException and makes no call when UsageLimiter rejects the run', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive']],
    ]);
    $ctx = executionContext(llmProvider: $llm, usageLimiter: new FakeUsageLimiter('rows_per_node exceeded'));

    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);

    expect(fn () => (new LlmOperation)->apply($input, llmConfig(), $ctx))
        ->toThrow(LimitExceededException::class, 'rows_per_node exceeded');

    expect($llm->callCount())->toBe(0);
});

it('sends only input_fields and an ordinal id per row, and merges {id,value} back by output_field', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive'], ['id' => 1, 'value' => 'negative']],
    ]);
    $ctx = executionContext(llmProvider: $llm);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'great product', 'col_price' => 10]],
        ['row_key' => 'r2', 'data' => ['col_text' => 'terrible', 'col_price' => 20]],
    ]);

    $result = (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'r1', 'data' => ['col_text' => 'great product', 'col_price' => 10, 'col_sentiment' => 'positive']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'terrible', 'col_price' => 20, 'col_sentiment' => 'negative']],
    ]);

    expect($llm->lastUserInput())->toContain('great product')
        ->not->toContain('col_price')
        ->not->toContain('10');
});

it('never calls the LLM again for a row already answered under the same instruction/fields/model', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive']],
    ]);
    $cache = new FakeLlmCacheRepository;
    $ctx = executionContext(llmProvider: $llm, llmCacheRepository: $cache);

    $row = ['row_key' => 'r1', 'data' => ['col_text' => 'great product']];
    $op = new LlmOperation;

    $op->apply(RowBatch::fromRows([$row]), llmConfig(), $ctx);
    expect($llm->callCount())->toBe(1);

    $result = $op->apply(RowBatch::fromRows([$row]), llmConfig(), $ctx);

    expect($llm->callCount())->toBe(1)
        ->and($result->rows()[0]['data']['col_sentiment'])->toBe('positive');
});

it('reports tokens spent on ctx only for real calls, not for cache hits', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive']],
    ], tokensPerCall: 42);
    $cache = new FakeLlmCacheRepository;
    $ctx = executionContext(llmProvider: $llm, llmCacheRepository: $cache);

    $row = ['row_key' => 'r1', 'data' => ['col_text' => 'great product']];
    $op = new LlmOperation;

    $op->apply(RowBatch::fromRows([$row]), llmConfig(), $ctx);
    expect($ctx->tokensUsed())->toBe(42);

    // Second call is a cache hit (same row/instruction/fields/model) — no
    // real request, so no additional tokens.
    $op->apply(RowBatch::fromRows([$row]), llmConfig(), $ctx);
    expect($ctx->tokensUsed())->toBe(42);
});

it('splits misses into batches no larger than the configured batch size', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'a'], ['id' => 1, 'value' => 'b']],
        [['id' => 0, 'value' => 'c']],
    ]);
    $ctx = executionContext(llmProvider: $llm);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
        ['row_key' => 'r3', 'data' => ['col_text' => 'x3']],
    ]);

    $result = (new LlmOperation(batchSize: 2))->apply($input, llmConfig(), $ctx);

    expect($llm->callCount())->toBe(2)
        ->and(array_column(array_column($result->rows(), 'data'), 'col_sentiment'))->toBe(['a', 'b', 'c']);
});

it('heartbeat receives progressively more of the row set as chunks complete', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'a'], ['id' => 1, 'value' => 'b']],
        [['id' => 0, 'value' => 'c']],
    ]);
    $reporter = new class implements ProgressReporter
    {
        public array $rowsSoFar = [];

        public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
        {
            $this->rowsSoFar[] = $rowsSoFar;
        }
    };
    $ctx = executionContext(llmProvider: $llm);
    $ctx->setProgressReporter($reporter);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
        ['row_key' => 'r3', 'data' => ['col_text' => 'x3']],
    ]);

    (new LlmOperation(batchSize: 2))->apply($input, llmConfig(), $ctx);

    expect($reporter->rowsSoFar)->toHaveCount(2)
        ->and(array_column(array_column($reporter->rowsSoFar[0], 'data'), 'col_sentiment'))->toBe(['a', 'b'])
        ->and(array_column(array_column($reporter->rowsSoFar[1], 'data'), 'col_sentiment'))->toBe(['a', 'b', 'c']);
});

it('sets output_field to null and logs a warning for a row the model drops from its response', function () {
    // Log::spy() persists between tests.
    Log::clearResolvedInstance('log');
    Log::spy();

    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive']], // id 1 missing from the response
    ]);
    $ctx = executionContext(llmProvider: $llm);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
    ]);

    $result = (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($result->rows()[0]['data']['col_sentiment'])->toBe('positive')
        ->and($result->rows()[1]['data']['col_sentiment'])->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('retries a failing batch with backoff and succeeds within maxAttempts', function () {
    $llm = new class implements LlmProvider
    {
        public int $calls = 0;

        public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
        {
            $this->calls++;

            if ($this->calls < 3) {
                throw new RuntimeException('503 overloaded');
            }

            return [['id' => 0, 'value' => 'ok']];
        }

        public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
        {
            return [];
        }

        public function supportsBatch(): bool
        {
            return false;
        }

        public function lastTokensUsed(): int
        {
            return 0;
        }

        public function modelId(string $tier): string
        {
            return 'fake-model';
        }
    };

    $ctx = executionContext(llmProvider: $llm);
    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);

    $result = (new LlmOperation(maxAttempts: 3, sleeper: fn () => null))->apply($input, llmConfig(), $ctx);

    expect($llm->calls)->toBe(3)
        ->and($result->rows()[0]['data']['col_sentiment'])->toBe('ok');
});

it('throws once a batch has failed maxAttempts times in a row', function () {
    $llm = new class implements LlmProvider
    {
        public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
        {
            throw new RuntimeException('503 overloaded');
        }

        public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
        {
            return [];
        }

        public function supportsBatch(): bool
        {
            return false;
        }

        public function lastTokensUsed(): int
        {
            return 0;
        }

        public function modelId(string $tier): string
        {
            return 'fake-model';
        }
    };

    $ctx = executionContext(llmProvider: $llm);
    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);

    (new LlmOperation(maxAttempts: 2, sleeper: fn () => null))->apply($input, llmConfig(), $ctx);
})->throws(RuntimeException::class, 'call failed after 2 attempts');

it('uses the batch endpoint when the provider supports it and the run is background', function () {
    $llm = new RecordingLlmProvider(
        batchSupported: true,
        batchResponses: [[
            [['id' => 0, 'value' => 'a'], ['id' => 1, 'value' => 'b']],
        ]],
    );
    $ctx = executionContext(llmProvider: $llm, isBackground: true);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
    ]);

    $result = (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($llm->batchCallCount())->toBe(1)
        ->and($llm->callCount())->toBe(0)
        ->and(array_column(array_column($result->rows(), 'data'), 'col_sentiment'))->toBe(['a', 'b']);
});

it('sends one userInput per chunk in a single generateBatch call', function () {
    $llm = new RecordingLlmProvider(
        batchSupported: true,
        batchResponses: [[
            [['id' => 0, 'value' => 'a']],
            [['id' => 0, 'value' => 'b']],
        ]],
    );
    $ctx = executionContext(llmProvider: $llm, isBackground: true);

    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
    ]);

    $result = (new LlmOperation(batchSize: 1))->apply($input, llmConfig(), $ctx);

    expect($llm->batchCallCount())->toBe(1)
        ->and($llm->batchCalls[0])->toHaveCount(2)
        ->and(array_column(array_column($result->rows(), 'data'), 'col_sentiment'))->toBe(['a', 'b']);
});

it('falls back to sequential calls when the provider does not support batching, even in background', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'a']],
    ], batchSupported: false);
    $ctx = executionContext(llmProvider: $llm, isBackground: true);

    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);

    (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($llm->callCount())->toBe(1)
        ->and($llm->batchCallCount())->toBe(0);
});

it('falls back to sequential calls when the run is foreground, even if the provider supports batching', function () {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'a']],
    ], batchSupported: true);
    $ctx = executionContext(llmProvider: $llm, isBackground: false);

    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);

    (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($llm->callCount())->toBe(1)
        ->and($llm->batchCallCount())->toBe(0);
});

it('asks again on the next run for a row the model gave no answer for', function (array $secondEntry) {
    $llm = new RecordingLlmProvider([
        [['id' => 0, 'value' => 'positive'], ...$secondEntry],
        [['id' => 0, 'value' => 'negative']],
    ]);
    $ctx = executionContext(llmProvider: $llm);
    $input = RowBatch::fromRows([
        ['row_key' => 'r1', 'data' => ['col_text' => 'x1']],
        ['row_key' => 'r2', 'data' => ['col_text' => 'x2']],
    ]);
    (new LlmOperation)->apply($input, llmConfig(), $ctx);

    $result = (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($llm->callCount())->toBe(2)
        ->and($llm->userInputs[1])->toContain('x2')->not->toContain('x1')
        ->and(array_column(array_column($result->rows(), 'data'), 'col_sentiment'))->toBe(['positive', 'negative']);
})->with([
    'row left out' => [[]],
    'entry without a value' => [[['id' => 1]]],
]);

it('caches a null the model did answer', function () {
    $llm = new RecordingLlmProvider([[['id' => 0, 'value' => null]]]);
    $ctx = executionContext(llmProvider: $llm);
    $input = RowBatch::fromRows([['row_key' => 'r1', 'data' => ['col_text' => 'x1']]]);
    (new LlmOperation)->apply($input, llmConfig(), $ctx);

    (new LlmOperation)->apply($input, llmConfig(), $ctx);

    expect($llm->callCount())->toBe(1);
});
