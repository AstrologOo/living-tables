<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Source step: the node's latest webhook payload as a raw JSON row for `extract`.
 *
 * config: {}
 */
class WebhookSourceOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        return RowBatch::fromRows([
            ['row_key' => null, 'data' => ['_raw' => $ctx->webhookPayload(), '_format' => 'json']],
        ], $input->columnsSchema());
    }

    public function requiredInputColumns(array $config): array
    {
        return [];
    }
}
