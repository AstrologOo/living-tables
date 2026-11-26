<?php

namespace App\Modules\Intake;

use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;

/**
 * Validates view criteria against schema/view-criteria.schema.json and resolves column names to col_id.
 */
class ViewCriteriaValidator
{
    private Validator $validator;

    private mixed $schema;

    public function __construct(?Validator $validator = null)
    {
        $this->validator = $validator ?? new Validator;
        $this->schema = json_decode(file_get_contents(__DIR__.'/schema/view-criteria.schema.json'));
    }

    /**
     * @param  array<string, mixed>  $criteria
     *
     * @throws ViewCriteriaValidationException
     */
    public function validate(array $criteria): void
    {
        // An empty array would encode as `[]`, but the schema expects an object.
        $data = $criteria === [] ? (object) [] : json_decode(json_encode($criteria));
        $result = $this->validator->validate($data, $this->schema);

        if (! $result->isValid()) {
            throw ViewCriteriaValidationException::fromError($result->error());
        }
    }

    /**
     * @param  array<string, mixed>  $criteria  already schema-valid; `filter` is a list of {field, cmp, value}
     * @param  array<string, string>  $colIdByName  column name => col_id, this node's own columns_schema
     * @return array<string, mixed> filter re-keyed to col_id => value or {cmp, value}; sort and unique fields resolved
     *
     * @throws ViewCriteriaValidationException if a referenced column doesn't exist on this node
     * @throws InvalidArgumentException if a column has several conditions; the generator doesn't retry this one, as
     *                                  the model could satisfy it by dropping a condition
     */
    public function resolveColumnReferences(array $criteria, array $colIdByName): array
    {
        $resolved = $criteria;

        if (isset($criteria['filter'])) {
            $resolved['filter'] = [];

            foreach ($criteria['filter'] as $entry) {
                $colId = $this->resolveName($entry['field'], $colIdByName);

                // The saved filter is keyed by column, so a second condition would replace the first.
                if (isset($resolved['filter'][$colId])) {
                    throw new InvalidArgumentException("Колонка «{$entry['field']}»: для одной колонки вид пока поддерживает одно условие.");
                }

                $resolved['filter'][$colId] = $entry['cmp'] === '=='
                    ? $entry['value']
                    : ['cmp' => $entry['cmp'], 'value' => $entry['value']];
            }
        }

        if (isset($criteria['sort_field'])) {
            $resolved['sort_field'] = $this->resolveName($criteria['sort_field'], $colIdByName);
        }

        if (isset($criteria['unique_field'])) {
            $resolved['unique_field'] = $this->resolveName($criteria['unique_field'], $colIdByName);
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $colIdByName
     */
    private function resolveName(string $name, array $colIdByName): string
    {
        if (! isset($colIdByName[$name])) {
            throw ViewCriteriaValidationException::unknownColumn($name, array_keys($colIdByName));
        }

        return $colIdByName[$name];
    }
}
