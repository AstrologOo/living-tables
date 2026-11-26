<?php

namespace App\Console\Support;

use App\Modules\Execution\Contracts\LlmCacheRepository;

/**
 * A throwaway LLM cache for tools that must call the model.
 */
class InMemoryLlmCacheRepository implements LlmCacheRepository
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function getMany(array $cacheKeys): array
    {
        return array_intersect_key($this->store, array_flip($cacheKeys));
    }

    public function putMany(array $entries): void
    {
        $this->store = [...$this->store, ...$entries];
    }
}
