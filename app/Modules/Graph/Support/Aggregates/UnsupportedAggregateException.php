<?php

namespace App\Modules\Graph\Support\Aggregates;

use RuntimeException;

class UnsupportedAggregateException extends RuntimeException
{
    public function __construct(string $function, string $driver)
    {
        parent::__construct("Aggregate function \"{$function}\" is not supported on the \"{$driver}\" driver.");
    }
}
