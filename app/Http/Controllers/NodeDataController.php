<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Services\NodeRowQueryBuilder;
use App\Modules\Graph\Support\Aggregates\AggregateDialectResolver;
use App\Modules\Graph\Support\Aggregates\UnsupportedAggregateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Read-only access to a node's current rows, with pagination, sorting and filters done in the database.
 */
class NodeDataController extends Controller
{
    use AuthorizesOwnership;

    public function index(Request $request, Node $node, NodeRowQueryBuilder $queryBuilder): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $filters = (array) $request->query('filter', []);

        $sort = $request->query('sort');

        if ($sort !== null && ! is_string($sort)) {
            abort(422, '"sort" must be a column name.');
        }

        $sortDesc = $sort !== null && str_starts_with($sort, '-');
        $sortColId = $sort !== null ? ($sortDesc ? substr($sort, 1) : $sort) : null;

        try {
            $query = $queryBuilder->build($node, $filters, $sortColId, $sortDesc);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $columnsSchema = $node->columns_schema ?? [];

        if ($query === null) {
            return response()->json([
                'data' => [],
                'columns' => $columnsSchema,
                'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
            ]);
        }

        $perPage = max(1, min($request->integer('per_page', 25), 200));
        $page = max(1, min($request->integer('page', 1), NodeRowQueryBuilder::MAX_PAGE));
        $rows = $query->paginate($perPage, page: $page);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (NodeRow $row) => $queryBuilder->formatRow($row))->all(),
            'columns' => $columnsSchema,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * One row of aggregates over the filtered rows: `?aggregate[alias][fn]=sum&aggregate[alias][col]=price`.
     */
    public function aggregate(Request $request, Node $node, NodeRowQueryBuilder $queryBuilder, AggregateDialectResolver $resolver): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        $filters = (array) $request->query('filter', []);
        $aggregates = (array) $request->query('aggregate', []);

        try {
            $result = $queryBuilder->aggregate($node, $filters, $aggregates, $resolver);
        } catch (InvalidArgumentException|UnsupportedAggregateException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => $result ?? array_fill_keys(array_keys($aggregates), null),
        ]);
    }
}
