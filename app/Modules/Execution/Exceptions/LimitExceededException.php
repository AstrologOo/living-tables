<?php

namespace App\Modules\Execution\Exceptions;

use RuntimeException;

/**
 * A usage limit was exceeded; the node fails without retries.
 */
class LimitExceededException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
