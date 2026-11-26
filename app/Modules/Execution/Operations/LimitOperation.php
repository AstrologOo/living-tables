<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Keeps the first rows.
 *
 * config: {count: int}
 */
class LimitOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        return $input->withRows(array_slice($input->rows(), 0, $config['count']));
    }

    public function requiredInputColumns(array $config): array
    {
        return [];
    }
}
