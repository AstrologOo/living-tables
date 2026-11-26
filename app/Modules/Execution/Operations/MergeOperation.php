<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\NodeNotReadableException;
use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use App\Modules\Execution\Support\ScalarKey;

/**
 * Joins the batch with another node's rows on one column, fetching only matching rows from a version pinned for
 * the run. The left row keeps its row_key.
 *
 * config: {with: node_id, on: col_id, how: 'inner'|'left'}
 */
class MergeOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $on = $config['on'];
        $how = $config['how'] ?? 'inner';
        $with = $config['with'];

        $onValues = [];
        foreach ($input->rows() as $row) {
            $value = $row['data'][$on] ?? null;

            if ($value !== null) {
                $onValues[] = $value;
            }
        }
        $onValues = array_values(array_unique($onValues));

        try {
            $versionId = $ctx->fixedMergeVersionId($with);
        } catch (NodeNotReadableException $e) {
            // The user's config is at fault, so they may see why.
            throw new DslException($e->getMessage(), previous: $e);
        }

        // A column missing on the right would silently match nothing, so check that it exists.
        if ($versionId !== null && ! $ctx->rows()->versionHasColumn($with, $versionId, $on)) {
            throw new SchemaDriftException([$on => $on]);
        }

        $rightByKey = [];
        if ($versionId !== null && $onValues !== []) {
            foreach ($ctx->rows()->mergeLookup($with, $versionId, $on, $onValues) as $row) {
                $rightByKey[ScalarKey::normalize($row['data'][$on] ?? null)][] = $row;
            }
        }

        $rows = [];

        foreach ($input->rows() as $leftRow) {
            $matches = $rightByKey[ScalarKey::normalize($leftRow['data'][$on] ?? null)] ?? [];

            if ($matches === [] && $how === 'left') {
                $rows[] = $leftRow;

                continue;
            }

            // row_key is unique within a version, so several matches get composite keys.
            $suffix = count($matches) > 1;

            foreach ($matches as $rightRow) {
                $rows[] = [
                    'row_key' => $suffix
                        ? $leftRow['row_key'].'::'.$rightRow['row_key']
                        : $leftRow['row_key'],
                    'data' => [...$leftRow['data'], ...$rightRow['data']],
                ];
            }
        }

        return $input->withRows($rows);
    }

    public function requiredInputColumns(array $config): array
    {
        return [$config['on']];
    }
}
