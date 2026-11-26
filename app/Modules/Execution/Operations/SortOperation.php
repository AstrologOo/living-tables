<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * Orders rows by a field.
 *
 * config: {field: col_id, order: 'asc'|'desc'}
 */
class SortOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $field = $config['field'];
        $order = $config['order'] ?? 'asc';

        $rows = $input->rows();
        usort($rows, fn (array $a, array $b) => $a['data'][$field] <=> $b['data'][$field]);

        if ($order === 'desc') {
            $rows = array_reverse($rows);
        }

        return $input->withRows($rows);
    }

    public function requiredInputColumns(array $config): array
    {
        return [$config['field']];
    }
}
