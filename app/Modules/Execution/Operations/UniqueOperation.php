<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Keeps the first row for each distinct value of a field.
 *
 * config: {field: col_id}
 */
class UniqueOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $field = $config['field'];
        $seen = [];
        $rows = [];

        foreach ($input->rows() as $row) {
            $key = json_encode($row['data'][$field] ?? null);

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = $row;
        }

        return $input->withRows($rows);
    }

    public function requiredInputColumns(array $config): array
    {
        return [$config['field']];
    }
}
