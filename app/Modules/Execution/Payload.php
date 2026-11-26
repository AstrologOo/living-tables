<?php

namespace App\Modules\Execution;

use Countable;

/**
 * Summary of a finished run: its id, columns schema and row counts. The rows themselves stay in RowStore.
 */
final class Payload implements Countable
{
    /**
     * @param  array<string, mixed>  $columnsSchema  col_id => {name, type, ...}
     * @param  array<int, array{op: string, rows_before: int, rows_after: int}>  $stepStats  step index => row counts crossing it, summed across every batch this run produced
     */
    private function __construct(
        private readonly string $runId,
        private readonly array $columnsSchema,
        private readonly int $rowCount,
        private readonly array $stepStats = [],
    ) {}

    /**
     * @param  array<string, mixed>  $columnsSchema
     * @param  array<int, array{op: string, rows_before: int, rows_after: int}>  $stepStats
     */
    public static function fromRun(string $runId, array $columnsSchema, int $rowCount, array $stepStats = []): self
    {
        return new self($runId, $columnsSchema, $rowCount, $stepStats);
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * @return array<string, mixed>
     */
    public function columnsSchema(): array
    {
        return $this->columnsSchema;
    }

    public function count(): int
    {
        return $this->rowCount;
    }

    /**
     * @return array<int, array{op: string, rows_before: int, rows_after: int}>
     */
    public function stepStats(): array
    {
        return $this->stepStats;
    }
}
