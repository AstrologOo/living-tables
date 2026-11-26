<?php

namespace App\Modules\Execution\Contracts;

use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

interface Operation
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch;

    /**
     * Fields this step reads from its input rows, checked for schema drift before apply(); [] if none.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function requiredInputColumns(array $config): array;
}
