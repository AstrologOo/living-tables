<?php

namespace Tests\Support\Fakes;

use App\Modules\Execution\Contracts\LlmCacheRepository;

class FakeLlmCacheRepository implements LlmCacheRepository
{
    /** @var array<string, mixed> */
    private array $store;

    /**
     * @param  array<string, mixed>  $seed
     */
    public function __construct(array $seed = [])
    {
        $this->store = $seed;
    }

    public function getMany(array $cacheKeys): array
    {
        return array_intersect_key($this->store, array_flip($cacheKeys));
    }

    public function putMany(array $entries): void
    {
        $this->store = [...$this->store, ...$entries];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->store;
    }
}
