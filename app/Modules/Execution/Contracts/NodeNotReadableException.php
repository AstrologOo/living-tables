<?php

namespace App\Modules\Execution\Contracts;

use LogicException;

/**
 * A step asked for a node its pipeline may not read. In Contracts so that a port implementation can throw it.
 */
class NodeNotReadableException extends LogicException
{
    public function __construct(string $nodeId)
    {
        // The same text whether the node is missing or someone else's.
        parent::__construct("Step references node \"{$nodeId}\", which is not one of this node's declared parents.");
    }
}
