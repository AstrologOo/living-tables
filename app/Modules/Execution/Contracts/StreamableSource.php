<?php

namespace App\Modules\Execution\Contracts;

use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;

/**
 * A source step that can yield its rows in batches instead of holding them all in memory. Used only for a
 * pipeline's first step.
 */
interface StreamableSource
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, RowBatch>
     */
    public function stream(array $config, ExecutionContext $ctx): iterable;
}
