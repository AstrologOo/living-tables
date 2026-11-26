<?php

namespace App\Modules\Graph\Services;

/**
 * Derives a `columns_schema` (col_id => name, type) from rows; col_id equals the field name. Used for the preview
 * draft and for a node whose schema nobody has confirmed.
 */
class ColumnSchemaInferenceService
{
    /**
     * A column gets a type only if all its non-null values share it, otherwise 'string': sorting a column typed
     * 'number' fails on a value that isn't one.
     *
     * @param  iterable<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @return array<string, array{name: string, type: string}>
     */
    public function infer(iterable $rows): array
    {
        $types = [];

        foreach ($rows as $row) {
            foreach ($row['data'] as $name => $value) {
                $types[$name] ??= null;

                if ($value !== null) {
                    $type = $this->guessType($value);
                    $types[$name] = in_array($types[$name], [null, $type], true) ? $type : 'string';
                }
            }
        }

        $schema = [];

        foreach ($types as $name => $type) {
            // All values null: 'string' assumes the least.
            $schema[$name] = ['name' => (string) $name, 'type' => $type ?? 'string'];
        }

        return $schema;
    }

    private function guessType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => 'string',
        };
    }
}
