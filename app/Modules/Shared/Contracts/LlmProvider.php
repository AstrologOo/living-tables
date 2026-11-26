<?php

namespace App\Modules\Shared\Contracts;

interface LlmProvider
{
    /**
     * @param  array<string, mixed>  $jsonSchema
     * @param  'cheap'|'standard'|'smart'  $tier
     * @return array<string, mixed>
     */
    public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array;

    /**
     * Many independent requests in one call; only used when supportsBatch() is true.
     *
     * @param  array<int, string>  $userInputs  one per request, in order
     * @param  array<string, mixed>  $jsonSchema  shared by every request
     * @param  'cheap'|'standard'|'smart'  $tier
     * @return array<int, array<string, mixed>> one decoded response per request, same order as $userInputs
     */
    public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array;

    public function supportsBatch(): bool;

    public function lastTokensUsed(): int;

    /**
     * An opaque id of the tier's model, without a network call; part of the LLM cache key.
     */
    public function modelId(string $tier): string;
}
