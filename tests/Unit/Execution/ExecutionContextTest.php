<?php

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use App\Modules\Shared\Contracts\DocumentParser;
use App\Modules\Shared\Contracts\LlmProvider;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeRowStore;
use Tests\Support\Fakes\FakeUsageLimiter;

it('exposes the ports it was built with', function () {
    $parentDataProvider = new class implements ParentDataProvider
    {
        public function latestPayload(string $nodeId, string $readerNodeId): RowBatch
        {
            return RowBatch::fromRows([['row_key' => $nodeId, 'data' => []]]);
        }
    };

    $llmProvider = new class implements LlmProvider
    {
        public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
        {
            return [];
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
            return "fake-model-{$tier}";
        }
    };

    $documentParser = new class implements DocumentParser
    {
        public function extractText(string $path): string
        {
            return '';
        }
    };

    $ctx = new ExecutionContext($parentDataProvider, $llmProvider, $documentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new FakeRowStore);

    expect($ctx->parentData())->toBe($parentDataProvider)
        ->and($ctx->llm())->toBe($llmProvider)
        ->and($ctx->documentParser())->toBe($documentParser)
        ->and($ctx->parentData()->latestPayload('n1', 'reader')->rows())->toBe([['row_key' => 'n1', 'data' => []]]);
});

it('lets an Operation receive its dependencies through the context, not resolve them itself', function () {
    $operation = new class implements Operation
    {
        public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
        {
            $extra = $ctx->parentData()->latestPayload($config['node'], $ctx->currentNodeId())->rows();

            return $input->withRows([...$input->rows(), ...$extra]);
        }

        public function requiredInputColumns(array $config): array
        {
            return [];
        }
    };

    $parentDataProvider = new class implements ParentDataProvider
    {
        public function latestPayload(string $nodeId, string $readerNodeId): RowBatch
        {
            return RowBatch::fromRows([['row_key' => 'parent-row', 'data' => ['x' => 1]]]);
        }
    };

    $llmProvider = new class implements LlmProvider
    {
        public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
        {
            return [];
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
            return "fake-model-{$tier}";
        }
    };

    $documentParser = new class implements DocumentParser
    {
        public function extractText(string $path): string
        {
            return '';
        }
    };

    $ctx = new ExecutionContext($parentDataProvider, $llmProvider, $documentParser, new FakeLlmCacheRepository, new FakeUsageLimiter, new FakeRowStore);

    $output = $operation->apply(RowBatch::fromRows([]), ['node' => 'parent-1'], $ctx);

    expect($output->rows())->toBe([['row_key' => 'parent-row', 'data' => ['x' => 1]]]);
});

it('accumulates tokensUsed across multiple addTokensUsed calls, starting from zero', function () {
    $ctx = executionContext();

    expect($ctx->tokensUsed())->toBe(0);

    $ctx->addTokensUsed(10);
    $ctx->addTokensUsed(5);

    expect($ctx->tokensUsed())->toBe(15);
});

it('heartbeat is a no-op when no ProgressReporter was set', function () {
    executionContext()->heartbeat(42); // throws nothing, asserts nothing more
})->throwsNoExceptions();

it('heartbeat delegates to the ProgressReporter once one is set', function () {
    $reporter = new class implements ProgressReporter
    {
        public array $received = [];

        public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
        {
            $this->received[] = $processed;
        }
    };

    $ctx = executionContext();
    $ctx->setProgressReporter($reporter);
    $ctx->heartbeat(7);
    $ctx->heartbeat();

    expect($reporter->received)->toBe([7, null]);
});

it('wantsCompleteData mirrors isBackground today', function () {
    expect(executionContext(isBackground: true)->wantsCompleteData())->toBeTrue()
        ->and(executionContext(isBackground: false)->wantsCompleteData())->toBeFalse();
});
