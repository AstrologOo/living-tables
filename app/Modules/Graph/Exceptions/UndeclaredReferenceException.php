<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

/**
 * A pipeline reads a node that isn't one of its node's declared parents.
 */
class UndeclaredReferenceException extends RuntimeException
{
    public function __construct(string $referencedId, string $nodeId)
    {
        parent::__construct("Step references node \"{$referencedId}\", which is not one of node {$nodeId}'s declared parents.");
    }
}
