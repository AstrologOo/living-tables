<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Graph\Models\Edge;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\GraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's graph: nodes, edges and the impact of changing a node.
 */
class GraphController extends Controller
{
    use AuthorizesOwnership;

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $nodeIds = Node::query()->where('user_id', $userId)->pluck('id');

        return response()->json([
            'nodes' => Node::query()->where('user_id', $userId)->get(['id', 'name', 'type', 'status'])->all(),
            'edges' => Edge::query()->whereIn('node_id', $nodeIds)->get(['node_id', 'depends_on_id'])->all(),
        ]);
    }

    public function impact(Request $request, Node $node, GraphService $graph): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $affected = $graph->affectedNodeIds($node->id);

        return response()->json([
            'node_id' => $node->id,
            'affected_count' => count($affected),
            'affected_node_ids' => $affected,
        ]);
    }
}
