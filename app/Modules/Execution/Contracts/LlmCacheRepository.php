<?php

namespace App\Modules\Execution\Contracts;

/**
 * Port to the per-row cache of LLM answers.
 */
interface LlmCacheRepository
{
    /**
     * @param  array<int, string>  $cacheKeys
     * @return array<string, mixed> cache_key => output, only for keys that hit
     */
    public function getMany(array $cacheKeys): array;

    /**
     * @param  array<string, mixed>  $entries  cache_key => output
     */
    public function putMany(array $entries): void;
}
