<?php

namespace App\Modules\Execution;

use Countable;

/**
 * An immutable batch of rows passed between pipeline steps.
 *
 * @phpstan-type Row array{row_key: ?string, data: array<string, mixed>}
 */
final class RowBatch implements Countable
{
    /** @var ?array<string, true> memoized union of every row's data keys, computed lazily */
    private ?array $availableDataKeys = null;

    /**
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<string, mixed>  $columnsSchema  col_id => {name, type, ...}
     */
    private function __construct(
        private readonly array $rows,
        private readonly array $columnsSchema = [],
    ) {}

    /**
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<string, mixed>  $columnsSchema
     */
    public static function fromRows(array $rows, array $columnsSchema = []): self
    {
        return new self($rows, $columnsSchema);
    }

    /**
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function columnsSchema(): array
    {
        return $this->columnsSchema;
    }

    /**
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     */
    public function withRows(array $rows): self
    {
        return new self($rows, $this->columnsSchema);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Keys present in any row, even with a null value, since JSON rows may omit fields.
     *
     * @return array<int, string>
     */
    public function availableDataKeys(): array
    {
        if ($this->availableDataKeys === null) {
            $keys = [];

            foreach ($this->rows as $row) {
                foreach (array_keys($row['data']) as $key) {
                    $keys[$key] = true;
                }
            }

            $this->availableDataKeys = $keys;
        }

        return array_keys($this->availableDataKeys);
    }
}
