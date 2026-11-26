<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Contracts\LlmProvider;

class FakeLlmProvider implements LlmProvider
{
    public function __construct(private readonly int $tokensPerCall = 0) {}

    public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
    {
        return [];
    }

    public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
    {
        return array_fill(0, count($userInputs), []);
    }

    public function supportsBatch(): bool
    {
        return false;
    }

    public function lastTokensUsed(): int
    {
        return $this->tokensPerCall;
    }

    public function modelId(string $tier): string
    {
        return "fake-model-{$tier}";
    }
}
