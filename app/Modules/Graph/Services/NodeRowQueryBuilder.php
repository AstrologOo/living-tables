<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Support\Aggregates\AggregateDialectResolver;
use App\Modules\Graph\Support\Aggregates\AggregateFunction;
use App\Modules\Graph\Support\NumericValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds filtered, sorted and aggregated queries over the rows of a node's current version.
 */
class NodeRowQueryBuilder
{
    /** The most rows one API response carries. */
    public const MAX_PER_PAGE = 200;

    /** The highest page number read; beyond it the offset would overflow. */
    public const MAX_PAGE = 1_000_000_000;

    /** The most conditions one column takes; it keeps the number of bound values within what a database accepts. */
    public const MAX_CONDITIONS_PER_COLUMN = 20;

    /** Filter comparisons and their SQL operators; `contains` is handled apart. */
    private const COMPARISONS = ['==' => '=', '!=' => '!=', '>' => '>', '<' => '<', '>=' => '>=', '<=' => '<='];

    /**
     * Returns null when the node has no version yet. $uniqueField keeps the first inserted row for each value, and
     * sorting applies to the deduplicated result.
     *
     * @param  array<string, mixed>  $filters  col_id => a value (equality), {cmp, value} or a non-empty list of
     *                                         {cmp, value}; cmp: ==, !=, >, <, >=, <=, contains. All are joined
     *                                         with AND.
     *
     * @throws InvalidArgumentException if a filter/sort/unique field references an undeclared column, or a filter is
     *                                  malformed or compares a number column with something else
     */
    public function build(Node $node, array $filters, ?string $sortColId, bool $sortDesc, ?string $uniqueField = null): ?Builder
    {
        $this->assertCriteria($node, $filters, $sortColId, $uniqueField);

        if ($node->current_version_id === null) {
            return null;
        }

        if ($uniqueField !== null) {
            $query = NodeRow::query()
                ->fromSub($this->rankedSubquery($node, $filters, $uniqueField), 'node_rows')
                ->where('node_rows.__unique_rank', 1);

            if ($sortColId !== null) {
                $this->orderByColumn($query, $node, $sortColId, $sortDesc);
            }

            return $query->orderBy('node_rows.id');
        }

        $query = NodeRow::query()
            ->join('node_version_rows', 'node_version_rows.row_id', '=', 'node_rows.id')
            ->where('node_version_rows.version_id', $node->current_version_id)
            ->select('node_rows.*');

        $this->applyFilters($query, $node, $filters);

        if ($sortColId !== null) {
            $this->orderByColumn($query, $node, $sortColId, $sortDesc);
        }

        // A total order keeps pagination stable; the second key separates row_keys that share one stored row.
        return $query->orderBy('node_rows.id')->orderBy('node_version_rows.id');
    }

    /**
     * Checks criteria against the node's columns without reading anything.
     *
     * @param  array<int|string, mixed>  $filters  same shape as build()
     *
     * @throws InvalidArgumentException as build() does
     */
    public function assertCriteria(Node $node, array $filters, ?string $sortColId, ?string $uniqueField): void
    {
        $this->assertFilters($node, $filters);

        if ($sortColId !== null) {
            $this->assertColumn($node, $sortColId);
        }

        if ($uniqueField !== null) {
            $this->assertColumn($node, $uniqueField);
        }
    }

    /**
     * Spells every condition out as {cmp, value}, the form a view is saved in: one per column as that object,
     * several as a list of them.
     *
     * @param  array<int|string, mixed>  $filters  already checked by assertCriteria()
     * @return array<int|string, array{cmp: mixed, value: mixed}|list<array{cmp: mixed, value: mixed}>>
     */
    public function fullFilters(array $filters): array
    {
        return array_map(function (mixed $criterion): array {
            $full = array_map(function (mixed $condition): array {
                [$cmp, $value] = $this->comparison($condition);

                return ['cmp' => $cmp, 'value' => $value];
            }, $this->conditions($criterion));

            return count($full) === 1 ? $full[0] : $full;
        }, $filters);
    }

    /**
     * In a number column, values that aren't numbers sort as NULL, after the numbers in both directions.
     */
    private function orderByColumn(Builder $query, Node $node, string $colId, bool $desc): void
    {
        $direction = $desc ? 'DESC' : 'ASC';

        if ($this->isNumberColumn($node, $colId)) {
            [$expr, $bindings] = NumericValue::sql($colId, DB::connection()->getDriverName());
            $query->orderByRaw("{$expr} {$direction} NULLS LAST", $bindings);

            return;
        }

        $query->orderByRaw("node_rows.data->>? {$direction}", [$colId]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function rankedSubquery(Node $node, array $filters, string $uniqueField): Builder
    {
        $query = NodeRow::query()
            ->join('node_version_rows', 'node_version_rows.row_id', '=', 'node_rows.id')
            ->where('node_version_rows.version_id', $node->current_version_id)
            ->selectRaw(
                'node_rows.*, ROW_NUMBER() OVER (PARTITION BY node_rows.data->>? ORDER BY node_rows.id) AS __unique_rank',
                [$uniqueField]
            );

        $this->applyFilters($query, $node, $filters);

        return $query;
    }

    /**
     * Applies filters already checked by assertFilters(). A number column is compared as numbers, so a value in it
     * that isn't one matches no comparison; `contains` always compares text.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder|BaseBuilder $query, Node $node, array $filters): void
    {
        foreach ($filters as $colId => $criterion) {
            foreach ($this->conditions($criterion) as $condition) {
                $this->applyCondition($query, $node, $colId, $condition);
            }
        }
    }

    private function applyCondition(Builder|BaseBuilder $query, Node $node, int|string $colId, mixed $condition): void
    {
        [$cmp, $value] = $this->comparison($condition);

        if ($cmp === 'contains') {
            $query->whereRaw('node_rows.data->>? LIKE ?', [$colId, '%'.$value.'%']);

            return;
        }

        $sqlOp = self::COMPARISONS[$cmp];

        if ($this->isNumberColumn($node, $colId)) {
            [$expr, $bindings] = NumericValue::sql($colId, DB::connection()->getDriverName());
            $query->whereRaw("{$expr} {$sqlOp} CAST(? AS NUMERIC)", [...$bindings, trim((string) $value)]);

            return;
        }

        $query->whereRaw("node_rows.data->>? {$sqlOp} ?", [$colId, $value]);
    }

    /**
     * What one column's filter holds: the items of a list, or the single condition it is.
     *
     * @return list<mixed>
     */
    private function conditions(mixed $criterion): array
    {
        return $this->isList($criterion) ? $criterion : [$criterion];
    }

    private function isList(mixed $criterion): bool
    {
        return is_array($criterion) && array_is_list($criterion);
    }

    /**
     * A bare value is short for equality.
     *
     * @return array{0: mixed, 1: mixed} cmp and value
     */
    private function comparison(mixed $criterion): array
    {
        return is_array($criterion) ? [$criterion['cmp'] ?? '==', $criterion['value'] ?? null] : ['==', $criterion];
    }

    /**
     * @param  array<int|string, mixed>  $filters  from a request or a saved view, so of any shape
     *
     * @throws InvalidArgumentException
     */
    private function assertFilters(Node $node, array $filters): void
    {
        foreach ($filters as $colId => $criterion) {
            $this->assertColumn($node, $colId);

            if ($this->isList($criterion)) {
                if (count($criterion) > self::MAX_CONDITIONS_PER_COLUMN) {
                    throw new InvalidArgumentException('Filter on column '.$this->quote($colId).' takes at most '.self::MAX_CONDITIONS_PER_COLUMN.' conditions.');
                }

                // A list holds conditions only; a bare value in it would read as one more allowed value.
                if ($criterion === [] || array_filter($criterion, is_array(...)) !== $criterion) {
                    throw $this->malformed($colId);
                }
            }

            foreach ($this->conditions($criterion) as $condition) {
                $this->assertCondition($node, $colId, $condition);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertCondition(Node $node, int|string $colId, mixed $condition): void
    {
        // Anything else, like a nested list or a misspelt key, would silently be compared with null.
        if (is_array($condition) && (! array_key_exists('value', $condition) || array_diff_key($condition, ['cmp' => 0, 'value' => 0]) !== [])) {
            throw $this->malformed($colId);
        }

        [$cmp, $value] = $this->comparison($condition);

        if ($cmp !== 'contains' && (! is_string($cmp) || ! isset(self::COMPARISONS[$cmp]))) {
            throw new InvalidArgumentException('Unsupported filter cmp: '.$this->quote($cmp).'.');
        }

        // Postgres refuses NUL and bytes that aren't UTF-8.
        if (($value !== null && ! is_scalar($value))
            || (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")))) {
            throw new InvalidArgumentException('Filter on column '.$this->quote($colId).' needs a single text or number value.');
        }

        if ($cmp !== 'contains' && $this->isNumberColumn($node, $colId) && ! NumericValue::accepts($value)) {
            throw new InvalidArgumentException('Filter on number column '.$this->quote($colId).' needs a number, not '.$this->quote($value).'.');
        }
    }

    private function malformed(int|string $colId): InvalidArgumentException
    {
        return new InvalidArgumentException('Filter on column '.$this->quote($colId).' must be a value, {cmp, value} or a list of {cmp, value}.');
    }

    /**
     * @throws InvalidArgumentException unless $colId is a column of the node's schema
     */
    private function assertColumn(Node $node, mixed $colId): void
    {
        if (! in_array($colId, array_keys($node->columns_schema ?? []), true)) {
            throw new InvalidArgumentException('Unknown column: '.$this->quote($colId).'.');
        }
    }

    private function isNumberColumn(Node $node, int|string $colId): bool
    {
        return ($node->columns_schema[$colId]['type'] ?? null) === 'number';
    }

    /** Anything a client sent, made safe to put into an error message. */
    private function quote(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : get_debug_type($value);

        return '"'.mb_strimwidth(mb_scrub($text, 'UTF-8'), 0, 60, '…', 'UTF-8').'"';
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRow(NodeRow $row): array
    {
        return ['row_key' => $row->row_key, ...$row->data];
    }

    /**
     * Computes aggregates over the filtered rows as a single result row.
     *
     * @param  array<string, mixed>  $filters  same shape as build()
     * @param  array<string, array{fn: string, col?: string}>  $aggregates  alias => spec; col is
     *                                                                      ignored/optional for 'count'
     * @return array<string, mixed>|null null when the node has no version yet
     *
     * @throws InvalidArgumentException if a filter/aggregate references an undeclared column, an
     *                                  unknown function, or an alias that isn't a safe SQL identifier
     *                                  (aliases are interpolated into the query, not bound)
     */
    public function aggregate(Node $node, array $filters, array $aggregates, AggregateDialectResolver $resolver): ?array
    {
        $this->assertFilters($node, $filters);

        if ($aggregates === []) {
            throw new InvalidArgumentException('At least one aggregate is required.');
        }

        foreach ($aggregates as $alias => $spec) {
            if (! is_string($alias) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $alias)) {
                throw new InvalidArgumentException('Invalid aggregate alias: '.$this->quote($alias).'.');
            }

            $fn = is_array($spec) ? $spec['fn'] ?? null : null;

            if (! in_array($fn, AggregateFunction::ALL, true)) {
                throw new InvalidArgumentException('Unknown aggregate function: '.$this->quote($fn).'.');
            }

            $col = $spec['col'] ?? null;

            // `count` needs no column, but one that is given must still be a name.
            if ($fn !== AggregateFunction::COUNT || ($col !== null && ! is_string($col))) {
                $this->assertColumn($node, $col);
            }

            if (in_array($fn, AggregateFunction::ARITHMETIC, true) && ! $this->isNumberColumn($node, $col)) {
                throw new InvalidArgumentException("\"{$fn}\" needs a number column, which ".$this->quote($col)." isn't.");
            }
        }

        if ($node->current_version_id === null) {
            return null;
        }

        $dialect = $resolver->resolve();

        $query = DB::table('node_rows')
            ->join('node_version_rows', 'node_version_rows.row_id', '=', 'node_rows.id')
            ->where('node_version_rows.version_id', $node->current_version_id);

        $this->applyFilters($query, $node, $filters);

        foreach ($aggregates as $alias => $spec) {
            $fn = $spec['fn'];
            $col = $spec['col'] ?? '';
            $columnType = $col !== '' ? ($node->columns_schema[$col]['type'] ?? null) : null;

            [$sql, $bindings] = $dialect->aggregateExpr($fn, $col, $columnType);

            $query->selectRaw("{$sql} AS \"{$alias}\"", $bindings);
        }

        $result = (array) $query->first();

        foreach ($aggregates as $alias => $spec) {
            $result[$alias] = $this->asNumber($result[$alias], $spec['fn'], $node->columns_schema[$spec['col'] ?? '']['type'] ?? null);
        }

        return $result;
    }

    /**
     * Postgres returns NUMERIC results as strings. The minimum or maximum of a text column stays the text it is.
     */
    private function asNumber(mixed $value, string $function, ?string $columnType): mixed
    {
        $isNumber = $columnType === 'number'
            || in_array($function, [AggregateFunction::COUNT, AggregateFunction::COUNT_DISTINCT], true);

        return $isNumber && is_string($value) && is_numeric($value) ? $value + 0 : $value;
    }
}
