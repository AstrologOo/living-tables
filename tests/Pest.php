<?php

use App\Modules\Execution\Contracts\LlmCacheRepository;
use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\Contracts\RowStore;
use App\Modules\Execution\Contracts\UsageLimiter;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\Payload;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Shared\Contracts\DocumentParser;
use App\Modules\Shared\Contracts\LlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\FakeDocumentParser;
use Tests\Support\Fakes\FakeLlmCacheRepository;
use Tests\Support\Fakes\FakeLlmProvider;
use Tests\Support\Fakes\FakeParentDataProvider;
use Tests\Support\Fakes\FakeRowStore;
use Tests\Support\Fakes\FakeUsageLimiter;
use Tests\TestCase;

// Test files that need RefreshDatabase add it themselves.
pest()->extend(TestCase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function executionContext(
    ?ParentDataProvider $parentDataProvider = null,
    ?LlmProvider $llmProvider = null,
    ?DocumentParser $documentParser = null,
    ?LlmCacheRepository $llmCacheRepository = null,
    ?UsageLimiter $usageLimiter = null,
    ?RowStore $rowStore = null,
    bool $isBackground = true,
): ExecutionContext {
    return new ExecutionContext(
        $parentDataProvider ?? new FakeParentDataProvider,
        $llmProvider ?? new FakeLlmProvider,
        $documentParser ?? new FakeDocumentParser,
        $llmCacheRepository ?? new FakeLlmCacheRepository,
        $usageLimiter ?? new FakeUsageLimiter,
        $rowStore ?? new FakeRowStore,
        $isBackground,
    );
}

/**
 * Runs a pipeline and returns its Payload and the rows it wrote.
 *
 * @param  array<int, array<string, mixed>>  $dsl
 * @return array{0: Payload, 1: array<int, array{row_key: ?string, data: array<string, mixed>}>}
 */
function runPipeline(array $dsl, string $nodeId = 'node-1', ?ExecutionContext $ctx = null): array
{
    $ctx ??= executionContext();
    $result = (new PipelineRunner)->run($nodeId, $dsl, $ctx);

    return [$result, $ctx->rows()->draftRows($result->runId(), $nodeId)];
}
