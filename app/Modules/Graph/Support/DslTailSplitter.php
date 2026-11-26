<?php

namespace App\Modules\Graph\Support;

/**
 * Separates a pipeline's trailing sort/unique/limit steps, which become view criteria instead of being
 * computed. Assumes the config is already validated.
 */
class DslTailSplitter
{
    private const TAIL_OPS = ['sort', 'unique', 'limit'];

    /**
     * @param  array<int, array<string, mixed>>  $dslConfig
     * @return array{
     *     executable: array<int, array<string, mixed>>,
     *     sort_field: ?string,
     *     sort_order: ?string,
     *     unique_field: ?string,
     *     limit_count: ?int,
     * }
     */
    public static function split(array $dslConfig): array
    {
        $noCriteria = [
            'sort_field' => null,
            'sort_order' => null,
            'unique_field' => null,
            'limit_count' => null,
        ];

        // A non-list is not a pipeline; leave its keys intact.
        if (! array_is_list($dslConfig)) {
            return ['executable' => $dslConfig, ...$noCriteria];
        }

        $tailStart = count($dslConfig);

        while ($tailStart > 0 && in_array($dslConfig[$tailStart - 1]['op'] ?? null, self::TAIL_OPS, true)) {
            $tailStart--;
        }

        $criteria = $noCriteria;

        for ($i = $tailStart; $i < count($dslConfig); $i++) {
            $step = $dslConfig[$i];

            match ($step['op']) {
                'sort' => [$criteria['sort_field'], $criteria['sort_order']] = [$step['field'], $step['order'] ?? 'asc'],
                'unique' => $criteria['unique_field'] = $step['field'],
                'limit' => $criteria['limit_count'] = $step['count'],
            };
        }

        return [
            'executable' => array_slice($dslConfig, 0, $tailStart),
            ...$criteria,
        ];
    }

    /**
     * Whether there is anything to build a default view from.
     *
     * @param  array{sort_field: ?string, unique_field: ?string, limit_count: ?int}  $criteria
     */
    public static function hasCriteria(array $criteria): bool
    {
        return $criteria['sort_field'] !== null
            || $criteria['unique_field'] !== null
            || $criteria['limit_count'] !== null;
    }
}
