<?php

namespace App\Modules\Execution\Exceptions;

use RuntimeException;

class UnknownOperationException extends RuntimeException
{
    public function __construct(public readonly string $operation)
    {
        parent::__construct("Unknown DSL operation: \"{$operation}\".");
    }
}
