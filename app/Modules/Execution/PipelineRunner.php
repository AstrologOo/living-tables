<?php

namespace App\Modules\Execution;

use App\Modules\Execution\Contracts\RowStore;
use App\Modules\Execution\Contracts\StreamableSource;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PipelineRunner
{
    private OperationFactory $operations;

    public function __construct(?OperationFactory $operations = null)
    {
        $this->operations = $operations ?? new OperationFactory;
    }

    /**
     * Runs a pipeline and writes its rows as drafts. A caller that must clean up after a failure passes its own
     * $runId.
     *
     * @param  array<int, array<string, mixed>>  $dslConfig  each step: {op: string, ...config}
     *
     * @throws PipelineException wrapping whatever the failing step threw, with its index and op
     */
    public function run(string $nodeId, array $dslConfig, ExecutionContext $ctx, ?string $runId = null): Payload
    {
        $runId ??= (string) Str::orderedUuid();
        $rowStore = $ctx->rows();
        $ctx->setCurrentNodeId($nodeId);
        $ctx->resetFixedMergeVersions();

        $steps = array_values($dslConfig);
        $chainSteps = $steps === [] ? [] : array_slice($steps, 1);
        $lastColumnsSchema = [];

        // Row counts per step, to show which one emptied the result.
        $stepStats = [];

        // Only the first step can yield several batches.
        foreach ($this->sourceBatches($steps, $ctx) as $batch) {
            if ($steps !== []) {
                $stepStats[0] ??= ['op' => $steps[0]['op'] ?? '(missing op)', 'rows_before' => 0, 'rows_after' => 0];
                $stepStats[0]['rows_after'] += $batch->count();
            }

            foreach ($chainSteps as $chainIndex => $step) {
                $index = $chainIndex + 1;
                $op = $step['op'] ?? '(missing op)';
                $config = $step;
                unset($config['op']);
                $rowsBefore = $batch->count();

                try {
                    $operation = $this->operations->make($op);

                    // Drift is checked on each step's input: after a filter or map, a vanished field looks like an empty result or
                    // a null. An empty input proves nothing, so it is skipped.
                    if ($batch->rows() !== []) {
                        $this->checkSchemaDrift($batch->availableDataKeys(), $operation->requiredInputColumns($config));
                    }

                    $batch = $operation->apply($batch, $config, $ctx);
                } catch (Throwable $e) {
                    throw new PipelineException($index, $op, $e);
                }

                $stepStats[$index] ??= ['op' => $op, 'rows_before' => 0, 'rows_after' => 0];
                $stepStats[$index]['rows_before'] += $rowsBefore;
                $stepStats[$index]['rows_after'] += $batch->count();
            }

            // Each batch is stored once, after its last step.
            $batch = $this->fillMissingRowKeys($batch);
            $lastColumnsSchema = $batch->columnsSchema();
            $rowStore->appendBatch($runId, $nodeId, $batch->rows());
        }

        // Checks the final rows against the node's declared schema, across all batches.
        if ($rowStore->count($runId, $nodeId) !== 0) {
            try {
                $this->checkFinalSchema($runId, $nodeId, $rowStore, $ctx->expectedColumnsSchema());
            } catch (SchemaDriftException $e) {
                throw new PipelineException(count($dslConfig) - 1, 'schema_drift', $e);
            }
        }

        return Payload::fromRun($runId, $lastColumnsSchema, $rowStore->count($runId, $nodeId), $stepStats);
    }

    /**
     * Yields the first step's batches; an empty pipeline yields one empty batch.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return iterable<int, RowBatch>
     *
     * @throws PipelineException wrapping whatever the source step threw, always at index 0
     */
    private function sourceBatches(array $steps, ExecutionContext $ctx): iterable
    {
        if ($steps === []) {
            yield RowBatch::fromRows([]);

            return;
        }

        $step = $steps[0];
        $op = $step['op'] ?? '(missing op)';
        $config = $step;
        unset($config['op']);

        try {
            $operation = $this->operations->make($op);

            if ($operation instanceof StreamableSource) {
                yield from $operation->stream($config, $ctx);

                return;
            }

            yield $operation->apply(RowBatch::fromRows([]), $config, $ctx);
        } catch (Throwable $e) {
            throw new PipelineException(0, $op, $e);
        }
    }

    /**
     * A row without a row_key gets its hash, so any change makes it a new row.
     */
    private function fillMissingRowKeys(RowBatch $batch): RowBatch
    {
        $rows = array_map(function (array $row) {
            if ($row['row_key'] !== null) {
                return $row;
            }

            return ['row_key' => $this->rowHash($row['data']), 'data' => $row['data']];
        }, $batch->rows());

        return $batch->withRows($rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rowHash(array $data): string
    {
        return hash('sha256', CanonicalJson::encode($data));
    }

    /**
     * Checks that every field the step reads exists in its input.
     *
     * @param  array<int, string>  $availableKeys
     * @param  array<int, string>  $requiredColumns
     *
     * @throws SchemaDriftException
     */
    private function checkSchemaDrift(array $availableKeys, array $requiredColumns): void
    {
        $missing = array_values(array_diff($requiredColumns, $availableKeys));

        if ($missing !== []) {
            throw new SchemaDriftException(array_combine($missing, $missing));
        }
    }

    /**
     * Throws if a declared column is missing from every row or its first non-null value has the wrong type. New
     * fields are only logged.
     *
     * @param  array<string, array{name: string, type?: string}>  $expectedColumnsSchema
     *
     * @throws SchemaDriftException
     */
    private function checkFinalSchema(string $runId, string $nodeId, RowStore $rowStore, array $expectedColumnsSchema): void
    {
        if ($expectedColumnsSchema === []) {
            return;
        }

        $availableKeys = $rowStore->availableDataKeys($runId, $nodeId);
        $samples = $rowStore->sampleValues($runId, $nodeId, array_keys($expectedColumnsSchema));

        $missing = [];

        foreach ($expectedColumnsSchema as $colId => $column) {
            if (! in_array($colId, $availableKeys, true)) {
                $missing[$colId] = $column['name'];

                continue;
            }

            $expectedType = $column['type'] ?? null;

            if ($expectedType !== null && array_key_exists($colId, $samples) && ! $this->typeMatches($samples[$colId], $expectedType)) {
                $missing[$colId] = $column['name'];
            }
        }

        $unknown = array_values(array_diff($availableKeys, array_keys($expectedColumnsSchema)));

        if ($unknown !== []) {
            Log::warning('PipelineRunner: final result has fields not in the declared schema', ['fields' => $unknown]);
        }

        if ($missing !== []) {
            throw new SchemaDriftException($missing, $unknown);
        }
    }

    private function typeMatches(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'number', 'integer' => is_numeric($value),
            'boolean' => is_bool($value),
            // Dates and other types come in too many shapes to check; any scalar passes.
            default => is_scalar($value),
        };
    }
}
