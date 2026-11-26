<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use App\Modules\Execution\Support\ScalarKey;

/**
 * Keeps rows whose field matches the comparison.
 *
 * config: {field: col_id, cmp: '=='|'!='|'>'|'<'|'>='|'<='|'contains', value: mixed}
 */
class FilterOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $field = $config['field'];
        $cmp = $config['cmp'];
        $value = $config['value'];

        $rows = array_values(array_filter(
            $input->rows(),
            fn (array $row) => $this->matches($row['data'][$field] ?? null, $cmp, $value)
        ));

        return $input->withRows($rows);
    }

    private function matches(mixed $actual, string $cmp, mixed $expected): bool
    {
        return match ($cmp) {
            // Not PHP's `==`: the configured value may differ in type from the data (see ScalarKey).
            '==' => ScalarKey::normalize($actual) === ScalarKey::normalize($expected),
            '!=' => ScalarKey::normalize($actual) !== ScalarKey::normalize($expected),
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            'contains' => is_string($actual) && str_contains($actual, (string) $expected),
            default => throw new DslException("Unsupported filter cmp: {$cmp}"),
        };
    }

    public function requiredInputColumns(array $config): array
    {
        return [$config['field']];
    }
}
