<?php

namespace App\Modules\Shared\Adapters;

use App\Modules\Shared\Contracts\LlmProvider;
use App\Modules\Shared\Exceptions\LlmUnavailableException;

/**
 * Stub used when no LLM provider is configured.
 */
class NullLlmProvider implements LlmProvider
{
    public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
    {
        throw new LlmUnavailableException('No LlmProvider adapter configured.');
    }

    public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
    {
        throw new LlmUnavailableException('No LlmProvider adapter configured.');
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
        throw new LlmUnavailableException('No LlmProvider adapter configured.');
    }
}
