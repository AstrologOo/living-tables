<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Graph\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live progress of a running node: unfinished rows, read from Redis.
 */
class NodeLiveController extends Controller
{
    use AuthorizesOwnership;

    public function show(Request $request, Node $node): Response
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $live = Cache::store('redis')->get("node:{$node->id}:live");

        if ($live === null) {
            // response()->json(null) would send `{}`.
            return response('null', 200, ['Content-Type' => 'application/json']);
        }

        return response()->json([
            'processed' => $live['processed'] ?? null,
            'rows' => $live['rows'] ?? [],
            'updated_at' => $live['updated_at'] ?? null,
            'draft' => true,
        ]);
    }
}
