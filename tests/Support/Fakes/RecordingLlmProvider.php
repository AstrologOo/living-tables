<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Contracts\LlmProvider;

class RecordingLlmProvider implements LlmProvider
{
    /** @var array<int, string> */
    public array $systemPrompts = [];

    /** @var array<int, string> */
    public array $userInputs = [];

    /** @var array<int, array<string, mixed>> */
    public array $jsonSchemas = [];

    /** @var array<int, string> */
    public array $tiers = [];

    /** @var array<int, array<int, string>> one entry per generateBatch() call: the user inputs it was given */
    public array $batchCalls = [];

    private int $callCount = 0;

    private int $batchCallCount = 0;

    /**
     * @param  array<int, array<string, mixed>>  $responses  one per generate() call, in order;
     *                                                       the last one repeats if there are more calls than responses
     * @param  array<int, array<int, array<string, mixed>>>  $batchResponses  one per generateBatch() call —
     *                                                                        each itself one response array per input in that call
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly bool $batchSupported = false,
        private readonly array $batchResponses = [],
        private readonly int $tokensPerCall = 0,
    ) {}

    public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
    {
        $this->systemPrompts[] = $systemPrompt;
        $this->userInputs[] = $userInput;
        $this->jsonSchemas[] = $jsonSchema;
        $this->tiers[] = $tier;

        $response = $this->responses[$this->callCount]
            ?? ($this->responses === [] ? [] : $this->responses[array_key_last($this->responses)]);
        $this->callCount++;

        return $response;
    }

    public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
    {
        $this->batchCalls[] = $userInputs;

        $response = $this->batchResponses[$this->batchCallCount]
            ?? (end($this->batchResponses) ?: array_fill(0, count($userInputs), []));
        $this->batchCallCount++;

        return $response;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function batchCallCount(): int
    {
        return $this->batchCallCount;
    }

    public function lastUserInput(): ?string
    {
        return end($this->userInputs) ?: null;
    }

    public function supportsBatch(): bool
    {
        return $this->batchSupported;
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
