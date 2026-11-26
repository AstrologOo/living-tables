<?php

namespace App\Modules\Execution\Contracts;

/**
 * Port to node row storage. A run writes its rows as drafts, batch by batch, which become a version when the
 * result is saved.
 */
interface RowStore
{
    /**
     * Deletes this node's drafts older than $olderThanSeconds, which must exceed the longest run: younger ones may
     * belong to a run in progress.
     */
    public function discardAbandonedDrafts(string $nodeId, int $olderThanSeconds): void;

    /**
     * Appends rows to the run's draft, skipping duplicates within this run only, so the draft stays complete even
     * when rows match committed ones.
     *
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     */
    public function appendBatch(string $runId, string $nodeId, array $rows): void;

    /**
     * All data keys found in the run's drafts.
     *
     * @return array<int, string>
     */
    public function availableDataKeys(string $runId, string $nodeId): array;

    /**
     * The first non-null value of each column among the run's drafts.
     *
     * @param  array<int, string>  $colIds
     * @return array<string, mixed>
     */
    public function sampleValues(string $runId, string $nodeId, array $colIds): array;

    /**
     * Number of draft rows the run has produced.
     */
    public function count(string $runId, string $nodeId): int;

    /**
     * Deletes the run's remaining drafts, after success or failure.
     */
    public function discardRun(string $runId, string $nodeId): void;

    /**
     * All draft rows of the run.
     *
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    public function draftRows(string $runId, string $nodeId): array;

    /**
     * A parent's current version id, or null if it has none yet. The entry point for reading another node.
     *
     * @throws NodeNotReadableException unless $nodeId is a declared parent of the reader with the same owner
     */
    public function currentVersionId(string $nodeId, string $readerNodeId): ?string;

    /**
     * Rows of a version whose $onField value is among $onValues.
     *
     * @param  array<int, mixed>  $onValues
     * @return array<int, array{row_key: string, data: array<string, mixed>}>
     */
    public function mergeLookup(string $nodeId, string $versionId, string $onField, array $onValues): array;

    /**
     * Whether any row of the version has the column; tells a missing join column from a lack of matches.
     */
    public function versionHasColumn(string $nodeId, string $versionId, string $colId): bool;
}
