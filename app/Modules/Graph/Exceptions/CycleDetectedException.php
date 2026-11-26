<?php

namespace App\Modules\Graph\Exceptions;

use RuntimeException;

class CycleDetectedException extends RuntimeException
{
    public function __construct(public readonly string $nodeId, public readonly string $dependsOnId)
    {
        parent::__construct(
            "Cannot add edge: node {$nodeId} depending on {$dependsOnId} would create a cycle in the graph."
        );
    }
}
