<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Execution\Contracts\RowStore;
use App\Modules\Graph\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RowStore over node_rows, using the query builder to keep large row sets out of Eloquent models.
 */
class GraphRowStore implements RowStore
{
    public function discardAbandonedDrafts(string $nodeId, int $olderThanSeconds): void
    {
        // Deletes only drafts no version references: a version may reuse a row that still carries a run_id.
        DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->whereNotNull('run_id')
            ->where('created_at', '<', now()->subSeconds($olderThanSeconds))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('node_version_rows')
                    ->whereColumn('node_version_rows.row_id', 'node_rows.id');
            })
            ->delete();
    }

    public function appendBatch(string $runId, string $nodeId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $hashedRows = array_map(
            fn (array $row) => $row + ['row_hash' => hash('sha256', CanonicalJson::encode($row['data']))],
            $rows
        );

        $existingHashes = DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->whereIn('row_hash', array_unique(array_column($hashedRows, 'row_hash')))
            ->pluck('row_hash')
            ->all();

        $newRows = array_filter(
            $hashedRows,
            fn (array $row) => ! in_array($row['row_hash'], $existingHashes, true)
        );

        if ($newRows === []) {
            return;
        }

        $userId = DB::table('nodes')->where('id', $nodeId)->value('user_id');

        DB::table('node_rows')->insert(array_map(fn (array $row) => [
            'id' => (string) Str::orderedUuid(),
            'user_id' => $userId,
            'node_id' => $nodeId,
            'row_key' => $row['row_key'],
            'row_hash' => $row['row_hash'],
            'data' => json_encode($row['data']),
            'run_id' => $runId,
            'created_at' => now(),
        ], $newRows));
    }

    public function availableDataKeys(string $runId, string $nodeId): array
    {
        $keys = [];

        DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->select('data')
            ->orderBy('id')
            ->cursor()
            ->each(function ($row) use (&$keys) {
                foreach (array_keys(json_decode($row->data, true)) as $key) {
                    $keys[$key] = true;
                }
            });

        return array_keys($keys);
    }

    public function sampleValues(string $runId, string $nodeId, array $colIds): array
    {
        $remaining = array_flip($colIds);
        $samples = [];

        DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->select('data')
            ->orderBy('id')
            ->cursor()
            ->each(function ($row) use (&$remaining, &$samples) {
                if ($remaining === []) {
                    return;
                }

                $data = json_decode($row->data, true);

                foreach (array_keys($remaining) as $colId) {
                    if (array_key_exists($colId, $data) && $data[$colId] !== null) {
                        $samples[$colId] = $data[$colId];
                        unset($remaining[$colId]);
                    }
                }
            });

        return $samples;
    }

    public function count(string $runId, string $nodeId): int
    {
        return DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->count();
    }

    public function discardRun(string $runId, string $nodeId): void
    {
        DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->delete();
    }

    public function draftRows(string $runId, string $nodeId): array
    {
        return DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->select('row_key', 'data')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => ['row_key' => $row->row_key, 'data' => json_decode($row->data, true)])
            ->all();
    }

    public function currentVersionId(string $nodeId, string $readerNodeId): ?string
    {
        ReadableParent::assert($nodeId, $readerNodeId);

        return DB::table('nodes')->where('id', $nodeId)->value('current_version_id');
    }

    public function mergeLookup(string $nodeId, string $versionId, string $onField, array $onValues): array
    {
        if ($onValues === []) {
            return [];
        }

        // $onField comes from user config, so it is bound, never interpolated.
        $placeholders = implode(',', array_fill(0, count($onValues), '?'));

        return DB::table('node_rows')
            ->join('node_version_rows', 'node_version_rows.row_id', '=', 'node_rows.id')
            ->where('node_version_rows.version_id', $versionId)
            ->where('node_rows.node_id', $nodeId)
            ->whereRaw("node_rows.data->>? IN ({$placeholders})", [$onField, ...array_map(strval(...), $onValues)])
            ->select('node_rows.row_key', 'node_rows.data')
            ->get()
            ->map(fn ($row) => ['row_key' => $row->row_key, 'data' => json_decode($row->data, true)])
            ->all();
    }

    public function versionHasColumn(string $nodeId, string $versionId, string $colId): bool
    {
        return DB::table('node_rows')
            ->join('node_version_rows', 'node_version_rows.row_id', '=', 'node_rows.id')
            ->where('node_version_rows.version_id', $versionId)
            ->where('node_rows.node_id', $nodeId)
            ->select('node_rows.data')
            ->limit(20)
            ->get()
            ->contains(fn ($row) => array_key_exists($colId, json_decode($row->data, true)));
    }
}
