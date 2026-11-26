<?php

namespace App\Modules\Intake;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\NodeRow;
use App\Modules\Graph\Models\NodeView;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\NodeRowQueryBuilder;
use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
use InvalidArgumentException;

/**
 * Creates a view from a phrase in one call, retrying when the criteria match no rows of a non-empty node.
 */
class ViewFromPhraseService
{
    private const MAX_GENERATION_ATTEMPTS = 3;

    public function __construct(
        private readonly ViewCriteriaGenerator $generator,
        private readonly NodeRowQueryBuilder $queryBuilder,
    ) {}

    /**
     * @return array{view: NodeView, rows: array<int, array<string, mixed>>} rows: a first look, at most NodeRowQueryBuilder::MAX_PER_PAGE
     *
     * @throws ViewCriteriaValidationException if no valid criteria could be generated
     * @throws InvalidArgumentException if the criteria can't be saved or queried against this node
     */
    public function create(Node $node, string $name, string $phrase): array
    {
        $columnsSchema = $node->columns_schema ?? [];
        $hasData = $node->current_version_id !== null;
        $feedback = null;

        for ($attempt = 1; ; $attempt++) {
            $startedAt = hrtime(true);

            try {
                $criteria = $this->generator->generate($phrase, $columnsSchema, $feedback);
            } catch (ViewCriteriaValidationException $e) {
                $this->recordGenerationStat($node, $startedAt, 'view_generation_failed');

                throw $e;
            }

            $this->recordGenerationStat($node, $startedAt, 'view_generation');

            $rows = $this->rowsFor($node, $criteria);

            if ($rows !== [] || ! $hasData || $attempt === self::MAX_GENERATION_ATTEMPTS) {
                break;
            }

            $feedback = 'Предыдущая попытка дала пустой результат, хотя у узла есть данные — '.
                'пересмотри условие фильтра (возможно, ссылка на неверное значение или слишком строгое сравнение).';
        }

        $view = NodeView::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'name' => $name,
            'filter' => isset($criteria['filter']) ? $this->queryBuilder->fullFilters($criteria['filter']) : null,
            'sort_field' => $criteria['sort_field'] ?? null,
            'sort_order' => $criteria['sort_order'] ?? null,
            'unique_field' => $criteria['unique_field'] ?? null,
            'limit_count' => $criteria['limit_count'] ?? null,
        ]);

        return ['view' => $view, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private function rowsFor(Node $node, array $criteria): array
    {
        $query = $this->queryBuilder->build(
            $node,
            $criteria['filter'] ?? [],
            $criteria['sort_field'] ?? null,
            ($criteria['sort_order'] ?? null) === 'desc',
            $criteria['unique_field'] ?? null,
        );

        if ($query === null) {
            return [];
        }

        $query->limit(min($criteria['limit_count'] ?? NodeRowQueryBuilder::MAX_PER_PAGE, NodeRowQueryBuilder::MAX_PER_PAGE));

        return $query->get()->map(fn (NodeRow $row) => $this->queryBuilder->formatRow($row))->all();
    }

    private function recordGenerationStat(Node $node, int $startedAt, string $status): void
    {
        RunStat::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'version_id' => null,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'tokens_spent' => $this->generator->lastTokensUsed(),
            'rows_total' => 0,
            'rows_changed' => 0,
            'status' => $status,
        ]);
    }
}
