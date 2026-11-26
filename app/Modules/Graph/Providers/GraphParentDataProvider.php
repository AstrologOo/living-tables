<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\RowBatch;
use App\Modules\Graph\Models\Node;

class GraphParentDataProvider implements ParentDataProvider
{
    public function latestPayload(string $nodeId, string $readerNodeId): RowBatch
    {
        ReadableParent::assert($nodeId, $readerNodeId);

        $node = Node::with('currentVersion.rows')->findOrFail($nodeId);

        $columnsSchema = $node->columns_schema ?? [];

        if ($node->currentVersion === null) {
            return RowBatch::fromRows([], $columnsSchema);
        }

        $rows = $node->currentVersion->rows
            ->map(fn ($row) => ['row_key' => $row->row_key, 'data' => $row->data])
            ->all();

        return RowBatch::fromRows($rows, $columnsSchema);
    }
}
