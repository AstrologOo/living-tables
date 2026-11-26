<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Graph\Exceptions\NodeHasDependentsException;
use App\Modules\Graph\Exceptions\ParentNotOwnedException;
use App\Modules\Graph\Exceptions\UndeclaredReferenceException;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeConfigVersion;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\NodeLifecycleService;
use App\Modules\Intake\DslValidator;
use App\Modules\Intake\Exceptions\DslValidationException;
use App\Modules\Intake\NodePreviewService;
use App\Modules\Shared\Exceptions\LlmUnavailableException;
use App\Support\PublicErrorMessage;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Node endpoints: create, preview a pipeline without saving, apply it, delete and roll back.
 */
class NodeController extends Controller
{
    use AuthorizesOwnership;

    /**
     * `trigger` and `schedule` apply to source nodes. The webhook URL with its secret is returned only here.
     */
    public function store(Request $request, NodeLifecycleService $nodes): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:source,transform',
            'name' => 'required|string',
            'parent_ids' => 'array',
            'parent_ids.*' => 'string',
            'trigger' => 'nullable|in:schedule,webhook',
            'schedule' => ['nullable', 'string', 'required_if:trigger,schedule', function ($attribute, $value, $fail) {
                if ($value !== null && ! CronExpression::isValidExpression($value)) {
                    $fail('The schedule must be a valid cron expression.');
                }
            }],
        ]);

        if (($data['trigger'] ?? null) !== null && $data['type'] !== 'source') {
            abort(422, 'Only a source node can have a trigger.');
        }

        try {
            $node = $nodes->create(
                $request->user()->id,
                $data['type'],
                $data['name'],
                $data['parent_ids'] ?? [],
                $data['trigger'] ?? null,
                $data['schedule'] ?? null,
            );
        } catch (ParentNotOwnedException $e) {
            abort(404, $e->getMessage());
        }

        $response = $node->toArray();

        if ($node->trigger === 'webhook') {
            $response['webhook_url'] = url("/webhooks/{$node->id}/{$node->webhook_secret}");
        }

        return response()->json($response, 201);
    }

    public function destroy(Request $request, Node $node, NodeLifecycleService $nodes): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        try {
            $nodes->delete($node);
        } catch (NodeHasDependentsException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function preview(Request $request, Node $node, NodePreviewService $previews): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $request->validate([
            'phrase' => 'nullable|string',
            'dsl_config' => 'nullable|array',
        ]);

        $phrase = $request->input('phrase');
        $dslConfig = $request->input('dsl_config');

        if ($phrase === null && $dslConfig === null) {
            abort(422, 'Provide either "phrase" or "dsl_config".');
        }

        try {
            return response()->json($previews->preview($node, $phrase, $dslConfig));
        } catch (DslValidationException $e) {
            return response()->json(['errors' => $e->errors], 422);
        } catch (LlmUnavailableException $e) {
            Log::warning("Node {$node->id} generation failed: {$e->getMessage()}");

            return response()->json(['error' => PublicErrorMessage::GENERATION_UNAVAILABLE], 503);
        } catch (RuntimeException|DslException|PipelineException $e) {
            Log::warning("Node {$node->id} preview failed: {$e->getMessage()}", ['exception' => $e::class]);

            return response()->json(['error' => PublicErrorMessage::for($e)], 422);
        }
    }

    /**
     * Saves a column schema the user confirmed; separate from apply().
     */
    public function confirmColumnsSchema(Request $request, Node $node, GraphService $graph): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $data = $request->validate([
            'columns_schema' => 'required|array',
            'columns_schema.*.name' => 'required|string',
            'columns_schema.*.type' => 'nullable|string',
        ]);

        $graph->confirmColumnsSchema($node, $data['columns_schema']);

        return response()->json($node->fresh());
    }

    public function apply(Request $request, Node $node, DslValidator $validator, NodeLifecycleService $nodes): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $dslConfig = $request->input('dsl_config');

        if (! is_array($dslConfig)) {
            abort(422, '"dsl_config" is required and must be an array of steps.');
        }

        try {
            // Only a config the user saves may use `webhook_source`.
            $validator->validate($dslConfig, allowWebhookSource: true);
        } catch (DslValidationException $e) {
            return response()->json(['errors' => $e->errors], 422);
        }

        try {
            $changed = $nodes->applyConfig($node, $dslConfig);
        } catch (UndeclaredReferenceException $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }

        return response()->json(['node' => $node->fresh(), 'changed' => $changed]);
    }

    /**
     * The node's pipeline history, newest first.
     */
    public function configVersions(Request $request, Node $node): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        return response()->json(
            NodeConfigVersion::query()
                ->where('node_id', $node->id)
                // Rows from one transaction share created_at; ordered UUIDs break the tie.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'dsl_config', 'tail', 'config_hash', 'created_at'])
        );
    }

    /**
     * Applies an earlier pipeline as a new change.
     */
    public function rollback(Request $request, Node $node, string $version, NodeLifecycleService $nodes): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        try {
            $changed = $nodes->rollbackConfig($node, $version);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        } catch (UndeclaredReferenceException $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }

        return response()->json(['node' => $node->fresh(), 'changed' => $changed]);
    }
}
