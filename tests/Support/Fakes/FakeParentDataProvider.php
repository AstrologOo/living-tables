<?php

namespace Tests\Support\Fakes;

use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\RowBatch;
use RuntimeException;

class FakeParentDataProvider implements ParentDataProvider
{
    /**
     * @param  array<string, RowBatch>  $payloads  node id => payload
     */
    public function __construct(private readonly array $payloads = []) {}

    public function latestPayload(string $nodeId, string $readerNodeId): RowBatch
    {
        return $this->payloads[$nodeId]
            ?? throw new RuntimeException("FakeParentDataProvider has no payload for node \"{$nodeId}\".");
    }
}
