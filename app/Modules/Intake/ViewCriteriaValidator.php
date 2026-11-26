<?php

namespace App\Modules\Intake;

use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
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
     * @return array<string, mixed> filter re-keyed to col_id => value or {cmp, value}, or a list of {cmp, value} for
     *                              a column with several conditions; sort and unique fields resolved
     *
     * @throws ViewCriteriaValidationException if a referenced column doesn't exist on this node
     */
    public function resolveColumnReferences(array $criteria, array $colIdByName): array
    {
        $resolved = $criteria;

        if (isset($criteria['filter'])) {
            $byColumn = [];

            foreach ($criteria['filter'] as $entry) {
                $byColumn[$this->resolveName($entry['field'], $colIdByName)][] = ['cmp' => $entry['cmp'], 'value' => $entry['value']];
            }

            $resolved['filter'] = array_map(fn (array $conditions): mixed => match (true) {
                count($conditions) > 1 => $conditions,
                $conditions[0]['cmp'] === '==' => $conditions[0]['value'],
                default => $conditions[0],
            }, $byColumn);
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
