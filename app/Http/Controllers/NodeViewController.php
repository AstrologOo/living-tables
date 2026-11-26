<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Services\NodeRowQueryBuilder;
use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
use App\Modules\Intake\ViewFromPhraseService;
use App\Modules\Shared\Exceptions\LlmUnavailableException;
use App\Support\PublicErrorMessage;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Saved filter and sort views over a node's current rows. A view created from a phrase is saved at once, as it
 * is cheap to delete.
 */
class NodeViewController extends Controller
{
    use AuthorizesOwnership;

    private const DEFAULT_PER_PAGE = 25;

    private const NAME_RULES = ['required', 'string', 'max:255', 'not_regex:/\x00/'];

    private const COLUMN_RULES = ['nullable', 'string', 'max:255', 'not_regex:/\x00/'];

    public function store(Request $request, Node $node, ViewFromPhraseService $views, NodeRowQueryBuilder $queryBuilder): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        if ($request->input('phrase') !== null) {
            return $this->storeFromPhrase($request, $node, $views);
        }

        $data = $request->validate([
            'name' => self::NAME_RULES,
            // Postgres can't store NUL.
            'filter' => ['array', function (string $attribute, mixed $value, Closure $fail) {
                if (str_contains((string) json_encode($value), '\u0000')) {
                    $fail('The filter must not contain NUL characters.');
                }
            }],
            'sort_field' => self::COLUMN_RULES,
            'sort_order' => 'nullable|in:asc,desc',
            'unique_field' => self::COLUMN_RULES,
            'limit_count' => 'nullable|integer|min:1|max:2147483647',
        ]);

        // A condition is a value (equality) or {cmp, value}. Laravel rules can't express that, and a rule for a nested
        // key would make validate() drop the short form, so the query builder checks the filter, as when reading.
        try {
            $queryBuilder->assertCriteria($node, $data['filter'] ?? [], $data['sort_field'] ?? null, $data['unique_field'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $view = NodeView::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'name' => $data['name'],
            'filter' => isset($data['filter']) ? $queryBuilder->fullFilters($data['filter']) : null,
            'sort_field' => $data['sort_field'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'unique_field' => $data['unique_field'] ?? null,
            'limit_count' => $data['limit_count'] ?? null,
        ]);

        return response()->json($view, 201);
    }

    private function storeFromPhrase(Request $request, Node $node, ViewFromPhraseService $views): JsonResponse
    {
        $data = $request->validate([
            'name' => self::NAME_RULES,
            'phrase' => 'required|string',
        ]);

        try {
            ['view' => $view, 'rows' => $rows] = $views->create($node, $data['name'], $data['phrase']);
        } catch (ViewCriteriaValidationException $e) {
            return response()->json(['errors' => $e->errors], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (LlmUnavailableException $e) {
            Log::warning("Node {$node->id} view generation failed: {$e->getMessage()}");

            return response()->json(['error' => PublicErrorMessage::GENERATION_UNAVAILABLE], 503);
        }

        return response()->json([...$view->toArray(), 'rows' => $rows], 201);
    }

    public function index(Request $request, Node $node): JsonResponse
    {
        $this->abortUnlessOwner($request, $node->user_id);

        return response()->json(
            // Ordered UUIDs break created_at ties.
            NodeView::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    /**
     * Pages are cut within the view's own limit, which paginate() would override.
     */
    public function rows(Request $request, NodeView $view, NodeRowQueryBuilder $queryBuilder): JsonResponse
    {
        $this->abortUnlessOwner($request, $view->user_id);

        $node = $view->node;

        try {
            $query = $queryBuilder->build($node, $view->filter ?? [], $view->sort_field, $view->sort_order === 'desc', $view->unique_field);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $columnsSchema = $node->columns_schema ?? [];

        if ($query === null) {
            return response()->json([
                'data' => [],
                'columns' => $columnsSchema,
                'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
            ]);
        }

        $perPage = max(1, min($request->integer('per_page', self::DEFAULT_PER_PAGE), NodeRowQueryBuilder::MAX_PER_PAGE));
        $page = max(1, min($request->integer('page', 1), NodeRowQueryBuilder::MAX_PAGE));

        $total = $query->toBase()->getCountForPagination();

        if ($view->limit_count !== null) {
            $total = min($total, $view->limit_count);
        }

        $offset = ($page - 1) * $perPage;
        $data = $offset < $total
            ? $query->offset($offset)->limit(min($perPage, $total - $offset))->get()
                ->map(fn (NodeRow $row) => $queryBuilder->formatRow($row))->all()
            : [];

        return response()->json([
            'data' => $data,
            'columns' => $columnsSchema,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function destroy(Request $request, NodeView $view): JsonResponse
    {
        $this->abortUnlessOwner($request, $view->user_id);

        $view->delete();

        return response()->json(null, 204);
    }
}
