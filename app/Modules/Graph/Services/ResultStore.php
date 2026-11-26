<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Exceptions\LockLostException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Models\NodeVersion;
use App\Modules\Graph\Support\CanonicalJson;
use App\Modules\Graph\ValueObjects\DiffResult;
use App\Modules\Graph\ValueObjects\RowSet;
use App\Modules\Graph\ValueObjects\StoreResult;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResultStore
{
    /**
     * Rows per statement when a run's drafts are promoted; bounds memory use.
     */
    private const PROMOTE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly InvalidationService $invalidation = new InvalidationService,
        private readonly ColumnSchemaInferenceService $schemaInference = new ColumnSchemaInferenceService,
    ) {}

    /**
     * Saves a run's result and, if the content changed, appends a version and marks fresh and failed children
     * `stale`, all in one transaction. The node ends up `stale` if a parent or the webhook payload changed during
     * the run, otherwise `fresh`. Unless a user confirmed the node's columns_schema, it is derived from the rows.
     *
     * @param  array<string, ?string>|null  $parentVersions  parent node id => current_version_id when the run started
     *
     * @throws LockLostException if the claim is no longer this run's; nothing is written
     */
    public function store(Node $node, RowSet $rows, ?array $parentVersions = null): StoreResult
    {
        return $rows->isRun()
            ? $this->storeFromRun($node, $rows->nodeId(), $rows->runId(), $parentVersions)
            : $this->storeFromArray($node, $rows, $parentVersions);
    }

    /**
     * The transaction behind every result. Locks are taken as parents, node, children, so that concurrent commits
     * wait for each other instead of deadlocking.
     *
     * @param  array<string, ?string>|null  $parentVersions
     * @param  array<string, array{name: string, type: string}>|null  $inferredSchema  saved unless a user confirmed one
     * @param  ?Closure(): array{0: array<string, mixed>, 1: NodeVersion}  $write  writes the new version and returns
     *                                                                             the node columns it changes; null
     *                                                                             if the content is unchanged
     * @return array{0: ?NodeVersion, 1: string, 2: array<int, string>} the new version, the status written and the
     *                                                                  children that became stale
     */
    private function commit(Node $node, ?array $parentVersions, ?array $inferredSchema, ?Closure $write = null): array
    {
        [$version, $changes, $staleChildIds] = DB::transaction(function () use ($node, $parentVersions, $inferredSchema, $write) {
            $parents = $this->lockParents($node);

            $current = Node::query()->whereKey($node->id)->lockForUpdate()->first();

            if ($current === null || $current->claim_token !== $node->claim_token) {
                throw new LockLostException("Node {$node->id} is no longer claimed by this run.");
            }

            if ($write !== null) {
                $this->lockChildren($node);
            }

            $outdated = ($node->data_revision !== null && $current->data_revision !== $node->data_revision)
                || ($parentVersions !== null && $this->parentsMovedOn($parentVersions, $parents));

            [$changes, $version] = $write === null ? [[], null] : $write();

            $changes += [
                'status' => $outdated ? 'stale' : 'fresh',
                'claim_token' => null,
                'consecutive_failures' => 0,
                'retry_at' => null,
            ];

            // No rows say nothing about the columns, so the known ones stay. Checked under the lock: the user may
            // have confirmed a schema during the run.
            if (! empty($inferredSchema) && ! $current->columns_schema_confirmed) {
                $changes['columns_schema'] = $inferredSchema;
            }

            // A query update skips the model's casts.
            Node::query()->whereKey($node->id)->update(isset($changes['columns_schema'])
                ? [...$changes, 'columns_schema' => json_encode($changes['columns_schema'])]
                : $changes);

            $staleChildIds = $version === null ? [] : $this->invalidation->markChildrenStaleIds($node);

            return [$version, $changes, $staleChildIds];
        });

        // Updated only after the commit, so a rollback leaves the model untouched.
        $node->forceFill($changes)->syncOriginalAttributes(array_keys($changes));

        return [$version, $changes['status'], $staleChildIds];
    }

    /**
     * @return array<string, ?string> parent node id => its current_version_id, read under the lock
     */
    private function lockParents(Node $node): array
    {
        $ids = DB::table('edges')->where('node_id', $node->id)->pluck('depends_on_id')->all();
        $versions = [];

        foreach ($this->inLockOrder($ids) as $id) {
            $versions[$id] = Node::query()->whereKey($id)->sharedLock()->value('current_version_id');
        }

        return $versions;
    }

    /**
     * NO KEY UPDATE, unlike FOR UPDATE, still lets edges to a child be inserted. sqlite ignores row locks.
     */
    private function lockChildren(Node $node): void
    {
        $ids = DB::table('edges')->where('depends_on_id', $node->id)->pluck('node_id')->all();

        foreach ($this->inLockOrder($ids) as $id) {
            Node::query()->whereKey($id)->lock('for no key update')->value('id');
        }
    }

    /**
     * Orders ancestors before descendants, then by id: nodes in one group may depend on each other.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function inLockOrder(array $ids): array
    {
        if (count($ids) < 2) {
            return $ids;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::select(<<<SQL
            WITH RECURSIVE up(id, ancestor, depth) AS (
                SELECT id, id, 0 FROM nodes WHERE id IN ({$placeholders})
                UNION
                SELECT up.id, e.depends_on_id, up.depth + 1
                FROM up INNER JOIN edges e ON e.node_id = up.ancestor
            )
            SELECT id FROM up GROUP BY id ORDER BY MAX(depth), id
            SQL, $ids);

        return array_column($rows, 'id');
    }

    /**
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     */
    private function parentsMovedOn(array $before, array $after): bool
    {
        ksort($before);
        ksort($after);

        return $after !== $before;
    }

    /**
     * @param  array<string, ?string>|null  $parentVersions
     */
    private function storeFromArray(Node $node, RowSet $rows, ?array $parentVersions): StoreResult
    {
        $hashedRows = array_map(
            fn (array $row) => $row + ['row_hash' => hash('sha256', CanonicalJson::encode($row['data']))],
            $rows->rows()
        );

        $contentHash = $this->contentHash($hashedRows);

        if ($contentHash === $node->content_hash) {
            [, $status] = $this->commit($node, $parentVersions, $this->missingSchema($node, $hashedRows));

            return new StoreResult(null, DiffResult::empty(), $status);
        }

        $previousRowHashesByKey = $this->currentRowHashesByKey($node);

        [$version, $status, $staleChildIds] = $this->commit($node, $parentVersions, $this->inferSchema($node, $hashedRows), function () use ($node, $hashedRows, $contentHash) {
            $version = NodeVersion::create([
                'user_id' => $node->user_id,
                'node_id' => $node->id,
                'content_hash' => $contentHash,
            ]);

            $rowIds = $this->resolveRowIds($node, $hashedRows);

            // row_key lives here, not only on node_rows: identical rows with different keys share one stored row.
            DB::table('node_version_rows')->insert(array_map(fn (string $rowId, array $row) => [
                'id' => (string) Str::orderedUuid(),
                'user_id' => $node->user_id,
                'version_id' => $version->id,
                'row_id' => $rowId,
                'row_key' => $row['row_key'],
            ], $rowIds, $hashedRows));

            return [['content_hash' => $contentHash, 'current_version_id' => $version->id], $version];
        });

        return new StoreResult($version, $this->diff($previousRowHashesByKey, $hashedRows), $status, $staleChildIds);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, array{name: string, type: string}>|null null if the node's schema is confirmed
     */
    private function inferSchema(Node $node, iterable $rows): ?array
    {
        return $node->columns_schema_confirmed ? null : $this->schemaInference->infer($rows);
    }

    /**
     * For unchanged content: only a node that has no schema yet needs one.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, array{name: string, type: string}>|null
     */
    private function missingSchema(Node $node, iterable $rows): ?array
    {
        return empty($node->columns_schema) ? $this->inferSchema($node, $rows) : null;
    }

    /**
     * A run's draft rows, one at a time.
     *
     * @return iterable<int, array{data: array<string, mixed>}>
     */
    private function draftData(string $nodeId, string $runId): iterable
    {
        $drafts = DB::table('node_rows')->where('node_id', $nodeId)->where('run_id', $runId)->orderBy('id')->select('data')->cursor();

        foreach ($drafts as $row) {
            yield ['data' => json_decode($row->data, true)];
        }
    }

    /**
     * @param  array<int, array{row_key: string, data: array<string, mixed>, row_hash: string}>  $hashedRows
     */
    private function contentHash(array $hashedRows): string
    {
        $hashes = array_column($hashedRows, 'row_hash');
        sort($hashes);

        return hash('sha256', implode('', $hashes));
    }

    /**
     * @return array<string, string> row_key => row_hash, for $node's current version
     */
    private function currentRowHashesByKey(Node $node): array
    {
        if ($node->current_version_id === null) {
            return [];
        }

        return NodeVersion::query()
            ->find($node->current_version_id)
            ?->rows()
            ->get(['node_rows.row_key', 'node_rows.row_hash'])
            ->pluck('row_hash', 'row_key')
            ->all() ?? [];
    }

    /**
     * @param  array<string, string>  $previousRowHashesByKey
     * @param  array<int, array{row_key: string, data: array<string, mixed>, row_hash: string}>  $hashedRows
     */
    private function diff(array $previousRowHashesByKey, array $hashedRows): DiffResult
    {
        $currentByKey = [];
        foreach ($hashedRows as $row) {
            $currentByKey[$row['row_key']] = $row['row_hash'];
        }

        $added = [];
        $changed = [];

        // PHP turns numeric array keys into ints; row_key must stay a string.
        foreach ($currentByKey as $key => $hash) {
            if (! array_key_exists($key, $previousRowHashesByKey)) {
                $added[] = (string) $key;
            } elseif ($previousRowHashesByKey[$key] !== $hash) {
                $changed[] = (string) $key;
            }
        }

        $removed = array_values(array_map(
            'strval',
            array_diff(array_keys($previousRowHashesByKey), array_keys($currentByKey))
        ));

        return new DiffResult($added, $changed, $removed);
    }

    /**
     * Reuses existing node_rows by row_hash (copy-on-write); inserts only
     * rows whose content is new for this node.
     *
     * @param  array<int, array{row_key: string, data: array<string, mixed>, row_hash: string}>  $hashedRows
     * @return array<int, string> node_rows.id, one per input row
     */
    private function resolveRowIds(Node $node, array $hashedRows): array
    {
        $existingByHash = NodeRow::query()
            ->where('node_id', $node->id)
            ->whereIn('row_hash', array_unique(array_column($hashedRows, 'row_hash')))
            ->get()
            ->keyBy('row_hash');

        $rowIds = [];

        foreach ($hashedRows as $row) {
            $existing = $existingByHash->get($row['row_hash']);

            if ($existing === null) {
                $existing = NodeRow::create([
                    'user_id' => $node->user_id,
                    'node_id' => $node->id,
                    'row_key' => $row['row_key'],
                    'row_hash' => $row['row_hash'],
                    'data' => $row['data'],
                ]);

                $existingByHash->put($row['row_hash'], $existing);
            }

            $rowIds[] = $existing->id;
        }

        return $rowIds;
    }

    /**
     * @param  array<string, ?string>|null  $parentVersions
     */
    private function storeFromRun(Node $node, string $nodeId, string $runId, ?array $parentVersions): StoreResult
    {
        // Computed before the transaction to keep locks short; the claim guarantees nobody else writes these rows.
        $contentHash = $this->contentHashFromRun($nodeId, $runId);

        if ($contentHash === $node->content_hash) {
            [, $status] = $this->commit($node, $parentVersions, $this->missingSchema($node, $this->draftData($nodeId, $runId)));

            return new StoreResult(null, DiffResult::empty(), $status);
        }

        $diff = $this->diffFromRun($nodeId, $runId, $node->current_version_id);

        $schema = $this->inferSchema($node, $this->draftData($nodeId, $runId));

        [$version, $status, $staleChildIds] = $this->commit($node, $parentVersions, $schema, function () use ($node, $nodeId, $runId, $contentHash) {
            $version = NodeVersion::create([
                'user_id' => $node->user_id,
                'node_id' => $node->id,
                'content_hash' => $contentHash,
            ]);

            $this->promoteRun($nodeId, $runId, $version->id, $node->user_id);

            return [['content_hash' => $contentHash, 'current_version_id' => $version->id], $version];
        });

        return new StoreResult($version, $diff, $status, $staleChildIds);
    }

    /**
     * Content hash of a run's drafts, equal to contentHash() of the same rows, read through a cursor.
     */
    private function contentHashFromRun(string $nodeId, string $runId): string
    {
        $hasher = hash_init('sha256');

        DB::table('node_rows')
            ->where('node_id', $nodeId)
            ->where('run_id', $runId)
            ->orderBy('row_hash')
            ->select('row_hash')
            ->cursor()
            ->each(function ($row) use ($hasher) {
                hash_update($hasher, $row->row_hash);
            });

        return hash_final($hasher);
    }

    /**
     * Row-level diff between a run's drafts and a version, computed in SQL so row data stays out of PHP.
     */
    private function diffFromRun(string $nodeId, string $runId, ?string $previousVersionId): DiffResult
    {
        $added = [];
        $changed = [];
        $removed = [];

        if ($previousVersionId === null) {
            DB::table('node_rows')
                ->where('node_id', $nodeId)
                ->where('run_id', $runId)
                ->select('row_key')
                ->cursor()
                ->each(function ($row) use (&$added) {
                    $added[] = $row->row_key;
                });

            return new DiffResult($added, [], []);
        }

        DB::table('node_rows as draft')
            ->where('draft.node_id', $nodeId)
            ->where('draft.run_id', $runId)
            ->leftJoin('node_version_rows as nvr', function ($join) use ($previousVersionId) {
                $join->on('nvr.row_key', '=', 'draft.row_key')
                    ->where('nvr.version_id', '=', $previousVersionId);
            })
            ->leftJoin('node_rows as prev', 'prev.id', '=', 'nvr.row_id')
            ->select('draft.row_key as row_key', 'draft.row_hash as new_hash', 'prev.row_hash as old_hash')
            ->cursor()
            ->each(function ($row) use (&$added, &$changed) {
                if ($row->old_hash === null) {
                    $added[] = $row->row_key;
                } elseif ($row->old_hash !== $row->new_hash) {
                    $changed[] = $row->row_key;
                }
            });

        DB::table('node_version_rows as nvr')
            ->where('nvr.version_id', $previousVersionId)
            ->leftJoin('node_rows as draft', function ($join) use ($nodeId, $runId) {
                $join->on('draft.row_key', '=', 'nvr.row_key')
                    ->where('draft.node_id', '=', $nodeId)
                    ->where('draft.run_id', '=', $runId);
            })
            ->whereNull('draft.id')
            ->select('nvr.row_key as row_key')
            ->cursor()
            ->each(function ($row) use (&$removed) {
                $removed[] = $row->row_key;
            });

        return new DiffResult($added, $changed, $removed);
    }

    /**
     * Links a run's drafts into a version, reusing stored rows with the same content and keeping only new ones.
     */
    private function promoteRun(string $nodeId, string $runId, string $versionId, int $userId): void
    {
        $versionRowsBatch = [];
        $redundantDraftIds = [];

        DB::table('node_rows as draft')
            ->where('draft.node_id', $nodeId)
            ->where('draft.run_id', $runId)
            ->leftJoin('node_rows as existing', function ($join) {
                $join->on('existing.node_id', '=', 'draft.node_id')
                    ->on('existing.row_hash', '=', 'draft.row_hash')
                    ->whereNull('existing.run_id');
            })
            ->select('draft.id as draft_id', 'draft.row_key as row_key', 'existing.id as existing_id')
            ->orderBy('draft.id')
            ->cursor()
            ->each(function ($row) use (&$versionRowsBatch, &$redundantDraftIds, $versionId, $userId) {
                $versionRowsBatch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'user_id' => $userId,
                    'version_id' => $versionId,
                    'row_id' => $row->existing_id ?? $row->draft_id,
                    'row_key' => $row->row_key,
                ];

                if ($row->existing_id !== null) {
                    $redundantDraftIds[] = $row->draft_id;
                }

                if (count($versionRowsBatch) >= self::PROMOTE_CHUNK_SIZE) {
                    DB::table('node_version_rows')->insert($versionRowsBatch);
                    $versionRowsBatch = [];
                }
            });

        if ($versionRowsBatch !== []) {
            DB::table('node_version_rows')->insert($versionRowsBatch);
        }

        // These drafts duplicate stored rows, which the version links to instead.
        foreach (array_chunk($redundantDraftIds, self::PROMOTE_CHUNK_SIZE) as $idsChunk) {
            DB::table('node_rows')->whereIn('id', $idsChunk)->delete();
        }

        // The remaining drafts are new content and become regular rows.
        DB::table('node_rows')->where('node_id', $nodeId)->where('run_id', $runId)->update(['run_id' => null]);
    }
}
