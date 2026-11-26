<?php

namespace App\Console\Commands;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\DriftResolutionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Command-line front end of DriftResolutionService.
 */
class ResolveSchemaDrift extends Command
{
    protected $signature = 'graph:resolve-drift
        {node : Node id currently in schema_drift}
        {--returned : the missing field(s) are confirmed back under the same name — no schema change, just retry}
        {--remove= : col_id to permanently remove from columns_schema (the field is gone for good)}';

    protected $description = 'Manually resolve a schema_drift node: confirm the field returned, or remove it for good';

    public function handle(DriftResolutionService $service): int
    {
        $node = Node::find($this->argument('node'));

        if ($node === null) {
            $this->error("No such node: {$this->argument('node')}.");

            return self::FAILURE;
        }

        $returned = (bool) $this->option('returned');
        $removeColId = $this->option('remove');
        $hasRemove = $removeColId !== null;

        if ($returned === $hasRemove) { // Exactly one of the two options is required.
            $this->error('Pass exactly one of --returned or --remove=<col_id>.');

            return self::FAILURE;
        }

        try {
            if ($returned) {
                $service->confirmFieldsReturned($node);
                $this->info("Node {$node->id}: confirmed fields returned, back to stale.");
            } else {
                $service->removeColumn($node, $removeColId);
                $this->info("Node {$node->id}: removed column \"{$removeColId}\", back to stale.");
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
