<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Execution\Contracts\LlmCacheRepository;
use App\Modules\Graph\Models\LlmRowCache;

class GraphLlmCacheRepository implements LlmCacheRepository
{
    public function getMany(array $cacheKeys): array
    {
        if ($cacheKeys === []) {
            return [];
        }

        return LlmRowCache::query()
            ->whereIn('cache_key', $cacheKeys)
            ->pluck('output', 'cache_key')
            ->all();
    }

    public function putMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $rows = array_map(
            fn (string $cacheKey, mixed $output) => [
                'cache_key' => $cacheKey,
                'output' => json_encode($output),
                'created_at' => now(),
            ],
            array_keys($entries),
            array_values($entries),
        );

        // The same key always means the same answer, so duplicates are ignored.
        LlmRowCache::query()->insertOrIgnore($rows);
    }
}
