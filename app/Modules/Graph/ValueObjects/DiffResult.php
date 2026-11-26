<?php

namespace App\Modules\Graph\ValueObjects;

/**
 * Row keys added, changed and removed between two versions of a node.
 */
final class DiffResult
{
    /**
     * @param  array<int, string>  $added  row_key present now, absent before
     * @param  array<int, string>  $changed  row_key present in both, row_hash differs
     * @param  array<int, string>  $removed  row_key present before, absent now
     */
    public function __construct(
        public readonly array $added,
        public readonly array $changed,
        public readonly array $removed,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function count(): int
    {
        return count($this->added) + count($this->changed) + count($this->removed);
    }
}
