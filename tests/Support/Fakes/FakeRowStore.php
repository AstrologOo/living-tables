<?php

namespace Tests\Support\Fakes;

use App\Modules\Execution\Contracts\RowStore;

/**
 * In-memory RowStore for Execution tests, with the same per-run drafts and deduplication as the real one.
 */
class FakeRowStore implements RowStore
{
    /** @var array<string, array<string, array<int, array{row_key: ?string, data: array<string, mixed>, row_hash: string}>>> node_id => run_id => draft rows */
    private array $drafts = [];

    /** @var array<string, ?string> node_id => current_version_id */
    private array $currentVersionIds = [];

    /** @var array<string, array<int, array{row_key: string, data: array<string, mixed>}>> version_id => rows */
    private array $versionRows = [];

    // Drafts here carry no timestamps and never outlive a test.
    public function discardAbandonedDrafts(string $nodeId, int $olderThanSeconds): void {}

    public function appendBatch(string $runId, string $nodeId, array $rows): void
    {
        $existingHashes = array_column($this->drafts[$nodeId][$runId] ?? [], 'row_hash');

        foreach ($rows as $row) {
            $hash = hash('sha256', json_encode($row['data']));

            if (in_array($hash, $existingHashes, true)) {
                continue;
            }

            $this->drafts[$nodeId][$runId][] = [...$row, 'row_hash' => $hash];
            $existingHashes[] = $hash;
        }
    }

    public function availableDataKeys(string $runId, string $nodeId): array
    {
        $keys = [];

        foreach ($this->drafts[$nodeId][$runId] ?? [] as $row) {
            foreach (array_keys($row['data']) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    public function sampleValues(string $runId, string $nodeId, array $colIds): array
    {
        $remaining = array_flip($colIds);
        $samples = [];

        foreach ($this->drafts[$nodeId][$runId] ?? [] as $row) {
            if ($remaining === []) {
                break;
            }

            foreach (array_keys($remaining) as $colId) {
                if (array_key_exists($colId, $row['data']) && $row['data'][$colId] !== null) {
                    $samples[$colId] = $row['data'][$colId];
                    unset($remaining[$colId]);
                }
            }
        }

        return $samples;
    }

    public function count(string $runId, string $nodeId): int
    {
        return count($this->drafts[$nodeId][$runId] ?? []);
    }

    public function discardRun(string $runId, string $nodeId): void
    {
        unset($this->drafts[$nodeId][$runId]);
    }

    /**
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    public function draftRows(string $runId, string $nodeId): array
    {
        return array_map(
            fn (array $row) => ['row_key' => $row['row_key'], 'data' => $row['data']],
            $this->drafts[$nodeId][$runId] ?? []
        );
    }

    public function currentVersionId(string $nodeId, string $readerNodeId): ?string
    {
        return $this->currentVersionIds[$nodeId] ?? null;
    }

    public function mergeLookup(string $nodeId, string $versionId, string $onField, array $onValues): array
    {
        $onValues = array_map('strval', $onValues);

        return array_values(array_filter(
            $this->versionRows[$versionId] ?? [],
            fn (array $row) => in_array((string) ($row['data'][$onField] ?? null), $onValues, true)
        ));
    }

    public function versionHasColumn(string $nodeId, string $versionId, string $colId): bool
    {
        foreach ($this->versionRows[$versionId] ?? [] as $row) {
            if (array_key_exists($colId, $row['data'])) {
                return true;
            }
        }

        return false;
    }

    // test helpers, not part of RowStore

    /**
     * @param  array<int, array{row_key: string, data: array<string, mixed>}>  $rows
     */
    public function seedVersion(string $nodeId, string $versionId, array $rows): void
    {
        $this->currentVersionIds[$nodeId] = $versionId;
        $this->versionRows[$versionId] = $rows;
    }
}
