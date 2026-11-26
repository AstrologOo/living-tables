<?php

namespace App\Modules\Execution\Contracts;

use App\Modules\Execution\RowBatch;

/**
 * Port to a parent node's current data.
 */
interface ParentDataProvider
{
    /**
     * @param  string  $readerNodeId  the node whose pipeline is running
     *
     * @throws NodeNotReadableException unless $nodeId is a declared parent of the reader with the same owner
     */
    public function latestPayload(string $nodeId, string $readerNodeId): RowBatch;
}
