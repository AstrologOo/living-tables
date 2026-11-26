<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Source step: extracts a document's text as one raw row for `extract`.
 *
 * config: {file_ref: string}
 */
class DocumentOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $text = $ctx->documentParser()->extractText($config['file_ref']);

        return RowBatch::fromRows([
            ['row_key' => null, 'data' => ['_raw' => $text, '_format' => 'text']],
        ], $input->columnsSchema());
    }

    public function requiredInputColumns(array $config): array
    {
        return [];
    }
}
