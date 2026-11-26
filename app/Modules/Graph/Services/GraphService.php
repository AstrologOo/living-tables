<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Exceptions\CycleDetectedException;
use App\Modules\Graph\Exceptions\UndeclaredReferenceException;
use App\Modules\Graph\Models\Edge;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Support\CanonicalJson;
use App\Modules\Graph\Support\DslTailSplitter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GraphService
{
    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, string>  $parentIds
     */
    public function createNode(array $attrs, array $parentIds = []): Node
    {
        $split = DslTailSplitter::split($attrs['dsl_config'] ?? []);
        $tail = $this->tailSteps($attrs['dsl_config'] ?? [], $split);
        $attrs['dsl_config'] = $split['executable'];
        $attrs['config_hash'] = hash('sha256', CanonicalJson::encode($split['executable']));
        // A schema given by the caller is a declared one, not to be replaced by inference.
        if (! empty($attrs['columns_schema'])) {
            $attrs['columns_schema_confirmed'] ??= true;
        }

        return DB::transaction(function () use ($attrs, $parentIds, $split, $tail) {
            $node = Node::create($attrs);
            $this->recordConfigVersion($node, $tail);
            $this->syncDefaultView($node, $split);

            foreach ($parentIds as $parentId) {
                $this->addEdge($node->id, $parentId);
            }

            return $node;
        });
    }

    /**
     * Saves a new pipeline, marks the node `stale` and cancels a run in progress; an equivalent config only updates
     * the default view. With a publisher, also wakes the worker.
     *
     * @param  array<int, array<string, mixed>>  $newDslConfig
     *
     * @throws UndeclaredReferenceException if a step reads a node that isn't a declared parent
     */
    public function updateDslConfig(Node $node, array $newDslConfig, ?NodeEventPublisher $eventPublisher = null): bool
    {
        return $this->saveConfig($node, $newDslConfig, $eventPublisher, replaceView: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $newDslConfig
     * @param  bool  $replaceView  false to leave the default view as it is, whatever the config's tail says
     */
    private function saveConfig(Node $node, array $newDslConfig, ?NodeEventPublisher $eventPublisher, bool $replaceView): bool
    {
        $this->assertReadsOnlyDeclaredParents($node, $newDslConfig);

        $split = DslTailSplitter::split($newDslConfig);
        $configHash = hash('sha256', CanonicalJson::encode($split['executable']));

        $tail = $replaceView ? $this->tailSteps($newDslConfig, $split) : null;

        if ($configHash === $node->config_hash) {
            // The tail isn't part of config_hash, so a tail-only edit is applied here.
            if ($replaceView) {
                $this->syncDefaultView($node, $split);
                $this->latestVersionOf($node)?->update(['tail' => $tail]);
            }

            return false;
        }

        $reset = ['status' => 'stale', 'claim_token' => null, 'consecutive_failures' => 0, 'retry_at' => null];

        DB::transaction(function () use ($node, $split, $configHash, $reset) {
            $node->update([
                'dsl_config' => $split['executable'],
                'config_hash' => $configHash,
            ]);

            // Clearing claim_token stops the old run from committing. A query update, because $node may be older than the
            // claim and Eloquent would skip columns that look unchanged.
            Node::query()->whereKey($node->id)->update($reset);
        });
        $node->forceFill($reset)->syncOriginalAttributes(array_keys($reset));

        $this->recordConfigVersion($node, $tail);

        if ($replaceView) {
            $this->syncDefaultView($node, $split);
        }

        $eventPublisher?->publishRecalcTask($node->id);

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $dslConfig
     *
     * @throws UndeclaredReferenceException if a `parent` or `merge` step reads a node that isn't a declared parent
     */
    public function assertReadsOnlyDeclaredParents(Node $node, array $dslConfig): void
    {
        $parentIds = null;

        foreach ($dslConfig as $step) {
            $referenced = match (is_array($step) ? $step['op'] ?? null : null) {
                'parent' => $step['node'] ?? null,
                'merge' => $step['with'] ?? null,
                default => null,
            };

            if ($referenced === null) {
                continue;
            }

            $parentIds ??= $node->parents()->where('nodes.user_id', $node->user_id)->pluck('nodes.id')->all();

            if (! in_array($referenced, $parentIds, true)) {
                throw new UndeclaredReferenceException((string) $referenced, $node->id);
            }
        }
    }

    /**
     * Queues a `failed` node for another run with a clean failure count.
     *
     * @return bool false if the node wasn't `failed`
     */
    public function restartFailed(Node $node): bool
    {
        $reset = ['status' => 'stale', 'consecutive_failures' => 0, 'retry_at' => null];

        // Conditional on the stored status: $node may have been loaded before the node failed.
        if (Node::query()->whereKey($node->id)->where('status', 'failed')->update($reset) === 0) {
            return false;
        }

        $node->forceFill($reset)->syncOriginalAttributes(array_keys($reset));

        return true;
    }

    /**
     * Keeps the node's default view in step with the trailing sort/unique/limit steps; deleted when there are none.
     *
     * @param  array{sort_field: ?string, sort_order: ?string, unique_field: ?string, limit_count: ?int}  $split
     */
    private function syncDefaultView(Node $node, array $split): void
    {
        if (! DslTailSplitter::hasCriteria($split)) {
            NodeView::query()->where('node_id', $node->id)->where('is_default', true)->delete();

            return;
        }

        NodeView::updateOrCreate(
            ['node_id' => $node->id, 'is_default' => true],
            [
                'user_id' => $node->user_id,
                'name' => '(default)',
                'sort_field' => $split['sort_field'],
                'sort_order' => $split['sort_order'],
                'unique_field' => $split['unique_field'],
                'limit_count' => $split['limit_count'],
            ]
        );
    }

    /**
     * Applies an earlier config, with the default view it had, as a new change with its own history entry. An entry
     * that doesn't record its view leaves the current one.
     *
     * @throws InvalidArgumentException if $versionId doesn't belong to $node
     */
    public function rollbackConfig(Node $node, string $versionId, ?NodeEventPublisher $eventPublisher = null): bool
    {
        $version = NodeConfigVersion::query()
            ->where('id', $versionId)
            ->where('node_id', $node->id)
            ->first();

        if ($version === null) {
            throw new InvalidArgumentException("No config version \"{$versionId}\" for node {$node->id}.");
        }

        return $this->saveConfig($node, [...$version->dsl_config, ...$version->tail ?? []], $eventPublisher, replaceView: $version->tail !== null);
    }

    /**
     * Saves a user-confirmed columns_schema without recomputing the node. A plain list of {name, type} is re-keyed
     * by name, which is how drift detection reads it.
     *
     * @param  array<string, array{name: string, type: string}>  $columnsSchema
     */
    public function confirmColumnsSchema(Node $node, array $columnsSchema): void
    {
        $keyed = [];

        foreach ($columnsSchema as $key => $column) {
            $keyed[is_int($key) ? $column['name'] : $key] = $column;
        }

        $node->update(['columns_schema' => $keyed, 'columns_schema_confirmed' => true]);
    }

    /**
     * @param  array<int|string, mixed>  $dslConfig
     * @param  array{executable: array<int|string, mixed>}  $split  of the same config
     * @return array<int, mixed> the steps after the computed ones
     */
    private function tailSteps(array $dslConfig, array $split): array
    {
        return array_values(array_slice($dslConfig, count($split['executable'])));
    }

    private function latestVersionOf(Node $node): ?NodeConfigVersion
    {
        return NodeConfigVersion::query()
            ->where('node_id', $node->id)
            ->where('config_hash', $node->config_hash)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<int, mixed>|null  $tail
     */
    private function recordConfigVersion(Node $node, ?array $tail): void
    {
        NodeConfigVersion::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'dsl_config' => $node->dsl_config,
            'tail' => $tail,
            'config_hash' => $node->config_hash,
        ]);
    }

    /**
     * @throws CycleDetectedException if $dependsOnId already (transitively) depends on $nodeId
     */
    public function addEdge(string $nodeId, string $dependsOnId): Edge
    {
        if ($nodeId === $dependsOnId || $this->pathExists($dependsOnId, $nodeId)) {
            throw new CycleDetectedException($nodeId, $dependsOnId);
        }

        return DB::transaction(function () use ($nodeId, $dependsOnId) {
            $node = Node::findOrFail($nodeId);

            return Edge::create([
                'user_id' => $node->user_id,
                'node_id' => $nodeId,
                'depends_on_id' => $dependsOnId,
            ]);
        });
    }

    /**
     * Whether $fromId already depends on $toId, directly or transitively.
     */
    private function pathExists(string $fromId, string $toId): bool
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE reachable(id) AS (
                SELECT depends_on_id FROM edges WHERE node_id = ?
                UNION
                SELECT e.depends_on_id FROM edges e INNER JOIN reachable r ON e.node_id = r.id
            )
            SELECT 1 FROM reachable WHERE id = ? LIMIT 1
            SQL, [$fromId, $toId]);

        return count($rows) > 0;
    }

    /**
     * All descendants of the node, for the impact preview. Read-only.
     *
     * @return array<int, string> descendant node ids, $nodeId itself excluded
     */
    public function affectedNodeIds(string $nodeId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE affected(id) AS (
                SELECT node_id FROM edges WHERE depends_on_id = ?
                UNION
                SELECT e.node_id FROM edges e INNER JOIN affected a ON e.depends_on_id = a.id
            )
            SELECT id FROM affected
            SQL, [$nodeId]);

        return array_column($rows, 'id');
    }
}
