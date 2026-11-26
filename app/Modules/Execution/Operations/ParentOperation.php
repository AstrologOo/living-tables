<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\NodeNotReadableException;
use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Source step: another node's current data.
 *
 * config: {node: string}
 */
class ParentOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        try {
            return $ctx->parentData()->latestPayload($config['node'], $ctx->currentNodeId());
        } catch (NodeNotReadableException $e) {
            // The user's config is at fault, so they may see why.
            throw new DslException($e->getMessage(), previous: $e);
        }
    }

    public function requiredInputColumns(array $config): array
    {
        return [];
    }
}
