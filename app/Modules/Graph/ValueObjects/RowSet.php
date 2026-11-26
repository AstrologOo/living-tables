<?php

namespace App\Modules\Graph\ValueObjects;

use Countable;
use LogicException;

/**
 * Rows to store for a node: an in-memory array, or a reference to a run's drafts that is processed in SQL.
 *
 * @phpstan-type Row array{row_key: string, data: array<string, mixed>}
 */
final class RowSet implements Countable
{
    /**
     * @param  array<int, array{row_key: string, data: array<string, mixed>}>  $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?string $runId = null,
        private readonly ?string $nodeId = null,
        private readonly ?int $runRowCount = null,
    ) {}

    public static function fromRun(string $runId, string $nodeId, int $rowCount): self
    {
        return new self(runId: $runId, nodeId: $nodeId, runRowCount: $rowCount);
    }

    public function isRun(): bool
    {
        return $this->runId !== null;
    }

    public function runId(): string
    {
        return $this->runId ?? throw new LogicException('RowSet::runId() is only available for a run-based RowSet (fromRun()).');
    }

    public function nodeId(): string
    {
        return $this->nodeId ?? throw new LogicException('RowSet::nodeId() is only available for a run-based RowSet (fromRun()).');
    }

    /**
     * @return array<int, array{row_key: string, data: array<string, mixed>}>
     */
    public function rows(): array
    {
        if ($this->isRun()) {
            throw new LogicException(
                'RowSet::rows() is unavailable for a run-based RowSet — its content lives in node_rows '
                .'and is read via SQL directly (see ResultStore), never materialized as a PHP array.'
            );
        }

        return $this->rows;
    }

    public function count(): int
    {
        return $this->isRun() ? $this->runRowCount : count($this->rows);
    }
}
