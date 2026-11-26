<?php

namespace App\Modules\Execution\Exceptions;

use RuntimeException;
use Throwable;

class PipelineException extends RuntimeException
{
    public function __construct(public readonly int $step, public readonly string $op, Throwable $previous)
    {
        parent::__construct(
            "Pipeline failed at step {$step} (\"{$op}\"): {$previous->getMessage()}",
            previous: $previous
        );
    }
}
