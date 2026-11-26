<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Exceptions\NodeHasDependentsException;
use App\Modules\Graph\Exceptions\ParentNotOwnedException;
use App\Modules\Graph\Exceptions\UndeclaredReferenceException;
use App\Modules\Graph\Models\Node;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A user's actions on a node (create, apply, roll back, delete), with their rules and the worker wake-up.
 */
class NodeLifecycleService
{
    public function __construct(
        private readonly GraphService $graph,
        private readonly WorkerWaker $worker,
    ) {}

    /**
     * Creates a node with an empty pipeline. A webhook secret is always generated here, as it also signs requests;
     * the caller shows it once.
     *
     * @param  array<int, string>  $parentIds
     *
     * @throws ParentNotOwnedException
     */
    public function create(int $userId, string $type, string $name, array $parentIds = [], ?string $trigger = null, ?string $schedule = null): Node
    {
        // Otherwise `parent` or `merge` steps could read another user's data.
        $uuids = array_filter($parentIds, Str::isUuid(...));

        if (count($uuids) !== count($parentIds) || Node::query()->whereIn('id', $uuids)->where('user_id', $userId)->count() !== count(array_unique($uuids))) {
            throw new ParentNotOwnedException('One or more parent_ids do not exist.');
        }

        $attrs = [
            'user_id' => $userId,
            'type' => $type,
            'name' => $name,
            'dsl_config' => [],
            'trigger' => $trigger,
            'schedule' => $schedule,
        ];

        if ($trigger === 'webhook') {
            $attrs['webhook_secret'] = Str::random(40);
        }

        return $this->graph->createNode($attrs, array_values(array_unique($parentIds)));
    }

    /**
     * Deletes a node nothing depends on; its data goes with it through cascading foreign keys.
     *
     * @throws NodeHasDependentsException
     */
    public function delete(Node $node): void
    {
        if ($node->children()->exists()) {
            throw new NodeHasDependentsException('Cannot delete a node other nodes still depend on — remove or repoint those first.');
        }

        $node->delete();
    }

    /**
     * Applying the pipeline a `failed` node already has restarts it.
     *
     * @param  array<int, array<string, mixed>>  $dslConfig  already validated
     * @return bool whether the pipeline actually changed
     *
     * @throws UndeclaredReferenceException
     */
    public function applyConfig(Node $node, array $dslConfig): bool
    {
        $changed = $this->graph->updateDslConfig($node, $dslConfig);

        if ($changed || $this->graph->restartFailed($node)) {
            $this->worker->wake($node->id);
        }

        return $changed;
    }

    /**
     * @return bool whether the pipeline actually changed
     *
     * @throws InvalidArgumentException if $versionId doesn't belong to $node
     * @throws UndeclaredReferenceException
     */
    public function rollbackConfig(Node $node, string $versionId): bool
    {
        $changed = $this->graph->rollbackConfig($node, $versionId);

        if ($changed) {
            $this->worker->wake($node->id);
        }

        return $changed;
    }
}
