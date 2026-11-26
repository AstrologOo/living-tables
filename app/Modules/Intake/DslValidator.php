<?php

namespace App\Modules\Intake;

use App\Modules\Intake\Exceptions\DslValidationException;
use Opis\JsonSchema\Validator;
use Symfony\Component\ExpressionLanguage\Lexer;
use Symfony\Component\ExpressionLanguage\Token;

/**
 * Validates a pipeline config against schema/dsl.schema.json before it is saved.
 */
class DslValidator
{
    /**
     * Expression-language literals, not column references.
     */
    private const EXPR_LITERALS = ['true', 'false', 'null'];

    /**
     * Functions allowed in `map` expressions; must match those MapOperation registers. `matches` is an operator.
     */
    private const EXPR_FUNCTIONS = ['min', 'max', 'extract_number', 'is_fibonacci', 'regex_extract'];

    private Validator $validator;

    private mixed $schema;

    /**
     * The schema plus `webhook_source`, kept apart so that the model is never offered it.
     */
    private mixed $schemaWithWebhookSource;

    /**
     * @var array<string, array{required: array<int, string>, properties: array<string, mixed>}>
     */
    private array $definitions;

    public function __construct(?Validator $validator = null)
    {
        $this->validator = $validator ?? new Validator;

        $schemaJson = file_get_contents(__DIR__.'/schema/dsl.schema.json');
        $this->schema = json_decode($schemaJson);
        $this->definitions = json_decode($schemaJson, associative: true)['definitions'];

        $this->schemaWithWebhookSource = json_decode($schemaJson);
        $this->schemaWithWebhookSource->items->oneOf[] = (object) ['$ref' => '#/definitions/webhook_source'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $config
     * @param  bool  $allowWebhookSource  only for a config a user saves, never for model output
     *
     * @throws DslValidationException
     */
    public function validate(array $config, bool $allowWebhookSource = false): void
    {
        $data = json_decode(json_encode($config));
        $result = $this->validator->validate($data, $allowWebhookSource ? $this->schemaWithWebhookSource : $this->schema);

        if (! $result->isValid()) {
            $friendly = $this->describeErrors($config);

            throw $friendly !== []
                ? DslValidationException::fromMessages($friendly)
                : DslValidationException::fromError($result->error());
        }

        $this->validatePaginatedExtractPaths($config);
        $this->validateTailPosition($config);
    }

    /**
     * Allows sort, unique and limit only as a trailing block, one of each; anywhere else they would never run.
     *
     * @param  array<int, array<string, mixed>>  $config  already schema-valid
     *
     * @throws DslValidationException
     */
    private function validateTailPosition(array $config): void
    {
        $tailOps = ['sort', 'unique', 'limit'];
        $config = array_values($config);
        $tailStart = count($config);

        while ($tailStart > 0 && in_array($config[$tailStart - 1]['op'], $tailOps, true)) {
            $tailStart--;
        }

        $seen = [];

        for ($i = $tailStart; $i < count($config); $i++) {
            $op = $config[$i]['op'];

            if (isset($seen[$op])) {
                throw DslValidationException::fromMessages([
                    "Step {$i} (\"{$op}\"): only one \"{$op}\" step is allowed in the trailing sort/unique/limit block.",
                ]);
            }

            $seen[$op] = true;
        }

        for ($i = 0; $i < $tailStart; $i++) {
            if (in_array($config[$i]['op'], $tailOps, true)) {
                throw DslValidationException::fromMessages([
                    "Step {$i} (\"{$config[$i]['op']}\"): sort/unique/limit only run as a trailing block, after every ".
                        'other step — this one is followed by a step that still needs to execute.',
                ]);
            }
        }
    }

    /**
     * Pagination already unwraps the items, so the following `extract` must use "$[*]".
     *
     * @param  array<int, array<string, mixed>>  $config  already schema-valid
     *
     * @throws DslValidationException
     */
    private function validatePaginatedExtractPaths(array $config): void
    {
        foreach ($config as $index => $step) {
            if (($step['op'] ?? null) !== 'fetch' || ! isset($step['pagination'])) {
                continue;
            }

            $next = $config[$index + 1] ?? null;

            if (($next['op'] ?? null) === 'extract' && ($next['path'] ?? null) !== '$[*]') {
                throw DslValidationException::fromMessages([
                    "Step {$index} (\"fetch\"): pagination flattens all pages into a single list — "
                        .'the following "extract" step (step '.($index + 1).') must use path "$[*]".',
                ]);
            }
        }
    }

    /**
     * Short per-step error messages taken from the schema, falling back to the raw schema error.
     *
     * @param  array<int, array<string, mixed>>  $config
     * @return array<int, string>
     */
    private function describeErrors(array $config): array
    {
        $messages = [];

        foreach ($config as $index => $step) {
            $op = is_array($step) ? ($step['op'] ?? null) : null;

            if (! is_string($op) || ! isset($this->definitions[$op])) {
                continue;
            }

            $definition = $this->definitions[$op];
            $missing = array_diff($definition['required'] ?? [], array_keys($step));
            $unexpected = array_diff(array_keys($step), array_keys($definition['properties'] ?? []));

            if ($missing !== []) {
                $messages[] = "Step {$index} (\"{$op}\"): missing required field(s): ".implode(', ', $missing).'.';
            }

            if ($unexpected !== []) {
                $messages[] = "Step {$index} (\"{$op}\"): field(s) not valid for this op: ".implode(', ', $unexpected).'.';
            }

            if ($op === 'map' && isset($step['fields']) && (! is_array($step['fields']) || array_is_list($step['fields']))) {
                $messages[] = "Step {$index} (\"map\"): \"fields\" must be a JSON object mapping new_col_id => "
                    .'source col_id or {"expr": ...}, not an array or scalar.';
            }
        }

        return $messages;
    }

    /**
     * Replaces column names with col_ids; columns created by `map` or `llm` become usable by later steps.
     *
     * @param  array<int, array<string, mixed>>  $config  already passed validate()
     * @param  array<string, string>  $colIdByName
     * @return array<int, array<string, mixed>>
     *
     * @throws DslValidationException
     */
    public function resolveColumnReferences(array $config, array $colIdByName): array
    {
        $resolved = [];

        foreach ($config as $step) {
            $resolvedStep = $this->resolveStep($step, $colIdByName);
            $resolved[] = $resolvedStep;
            $colIdByName = [...$colIdByName, ...$this->introducedColumns($resolvedStep)];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $step  already resolved
     * @return array<string, string> name => col_id; a new column's name is its col_id
     */
    private function introducedColumns(array $step): array
    {
        return match ($step['op']) {
            'map' => array_combine(array_keys($step['fields']), array_keys($step['fields'])),
            'llm' => [$step['output_field'] => $step['output_field']],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $colIdByName
     * @return array<string, mixed>
     */
    private function resolveStep(array $step, array $colIdByName): array
    {
        return match ($step['op']) {
            'filter', 'sort', 'unique' => [
                ...$step,
                'field' => $this->resolveName($step['field'], $colIdByName),
            ],
            'merge' => [...$step, 'on' => $this->resolveName($step['on'], $colIdByName)],
            'extract' => isset($step['row_key_field'])
                ? [...$step, 'row_key_field' => $this->resolveName($step['row_key_field'], $colIdByName)]
                : $step,
            'map' => [...$step, 'fields' => $this->resolveMapFields($step['fields'], $colIdByName)],
            'llm' => [
                ...$step,
                'input_fields' => array_map(
                    fn (string $field) => $this->resolveName($field, $colIdByName),
                    $step['input_fields'],
                ),
            ],
            default => $step,
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $colIdByName
     * @return array<string, mixed>
     */
    private function resolveMapFields(array $fields, array $colIdByName): array
    {
        $resolved = [];

        foreach ($fields as $newColId => $spec) {
            $resolved[$newColId] = is_array($spec)
                ? ['expr' => $this->resolveExpression($spec['expr'], $colIdByName)]
                : $this->resolveName($spec, $colIdByName);
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $colIdByName
     */
    private function resolveName(string $name, array $colIdByName): string
    {
        return $colIdByName[$name] ?? throw DslValidationException::unknownColumn($name, array_keys($colIdByName));
    }

    /**
     * Replaces column names in an expression using the language's tokenizer, so strings and operators are left
     * alone; unknown functions are rejected.
     *
     * @param  array<string, string>  $colIdByName
     */
    private function resolveExpression(string $expr, array $colIdByName): string
    {
        $stream = (new Lexer)->tokenize($expr);
        $result = '';
        $cursor = 0;

        while (! $stream->isEOF()) {
            $token = $stream->current;

            if ($token->type === Token::NAME_TYPE && ! in_array(strtolower($token->value), self::EXPR_LITERALS, true)) {
                // Token cursors start at 1.
                $start = $token->cursor - 1;
                $tokenEnd = $start + strlen($token->value);
                $result .= substr($expr, $cursor, $start - $cursor);

                if (ltrim(substr($expr, $tokenEnd)) !== '' && str_starts_with(ltrim(substr($expr, $tokenEnd)), '(')) {
                    if (! in_array($token->value, self::EXPR_FUNCTIONS, true)) {
                        throw DslValidationException::unknownFunction($token->value, self::EXPR_FUNCTIONS);
                    }

                    $result .= $token->value;
                } else {
                    $result .= $this->resolveName($token->value, $colIdByName);
                }

                $cursor = $tokenEnd;
            }

            $stream->next();
        }

        return $result.substr($expr, $cursor);
    }
}
