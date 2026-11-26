<?php

namespace App\Modules\Execution;

use App\Modules\Execution\Contracts\LlmCacheRepository;
use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\Contracts\RowStore;
use App\Modules\Execution\Contracts\UsageLimiter;
use App\Modules\Shared\Contracts\DocumentParser;
use App\Modules\Shared\Contracts\LlmProvider;

/**
 * The ports and per-run values operations need.
 */
final class ExecutionContext
{
    public function __construct(
        private readonly ParentDataProvider $parentDataProvider,
        private readonly LlmProvider $llmProvider,
        private readonly DocumentParser $documentParser,
        private readonly LlmCacheRepository $llmCacheRepository,
        private readonly UsageLimiter $usageLimiter,
        private readonly RowStore $rowStore,
        // Background runs may use a provider's batch endpoint and fetch every page; preview passes false.
        private readonly bool $isBackground = true,
    ) {}

    // The node whose pipeline is running; set by PipelineRunner. Reads of other nodes are checked against it.
    private string $currentNodeId = '';

    public function setCurrentNodeId(string $nodeId): void
    {
        $this->currentNodeId = $nodeId;
    }

    public function currentNodeId(): string
    {
        return $this->currentNodeId;
    }

    // Whose usage limits apply; set before each run.
    private ?string $currentUserId = null;

    public function setCurrentUserId(?string $userId): void
    {
        $this->currentUserId = $userId;
    }

    public function currentUserId(): ?string
    {
        return $this->currentUserId;
    }

    // Running total over the context's lifetime; compare before and after a run to get its cost.
    private int $tokensUsed = 0;

    public function addTokensUsed(int $tokens): void
    {
        $this->tokensUsed += $tokens;
    }

    public function tokensUsed(): int
    {
        return $this->tokensUsed;
    }

    public function parentData(): ParentDataProvider
    {
        return $this->parentDataProvider;
    }

    public function rows(): RowStore
    {
        return $this->rowStore;
    }

    // Merge target id => version id, pinned for the run so every batch sees the same data.
    private array $fixedMergeVersions = [];

    public function resetFixedMergeVersions(): void
    {
        $this->fixedMergeVersions = [];
    }

    public function fixedMergeVersionId(string $nodeId): ?string
    {
        if (! array_key_exists($nodeId, $this->fixedMergeVersions)) {
            $this->fixedMergeVersions[$nodeId] = $this->rowStore->currentVersionId($nodeId, $this->currentNodeId);
        }

        return $this->fixedMergeVersions[$nodeId];
    }

    public function llm(): LlmProvider
    {
        return $this->llmProvider;
    }

    public function documentParser(): DocumentParser
    {
        return $this->documentParser;
    }

    public function llmCache(): LlmCacheRepository
    {
        return $this->llmCacheRepository;
    }

    public function isBackground(): bool
    {
        return $this->isBackground;
    }

    /**
     * Whether the result must be complete; preview only needs the first page.
     */
    public function wantsCompleteData(): bool
    {
        return $this->isBackground;
    }

    public function usageLimiter(): UsageLimiter
    {
        return $this->usageLimiter;
    }

    // The node's declared columns_schema, set before each run for drift detection.
    private array $expectedColumnsSchema = [];

    public function setExpectedColumnsSchema(array $columnsSchema): void
    {
        $this->expectedColumnsSchema = $columnsSchema;
    }

    /**
     * @return array<string, array{name: string, type?: string}>
     */
    public function expectedColumnsSchema(): array
    {
        return $this->expectedColumnsSchema;
    }

    // The node's latest webhook payload, set before each run.
    private mixed $webhookPayload = null;

    public function setWebhookPayload(mixed $payload): void
    {
        $this->webhookPayload = $payload;
    }

    public function webhookPayload(): mixed
    {
        return $this->webhookPayload;
    }

    // No-op unless set, so operations can always call heartbeat().
    private ?ProgressReporter $progressReporter = null;

    public function setProgressReporter(?ProgressReporter $progressReporter): void
    {
        $this->progressReporter = $progressReporter;
    }

    // May throw when the run's time budget has elapsed.
    public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
    {
        $this->progressReporter?->heartbeat($processed, $rowsSoFar);
    }
}
