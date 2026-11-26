<?php

namespace App\Modules\Shared\Adapters\Llm;

use App\Modules\Shared\Contracts\LlmProvider;
use App\Modules\Shared\Exceptions\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;

/**
 * LlmProvider implementation for Gemini.
 */
class GeminiAdapter implements LlmProvider
{
    /**
     * Enum fields whose letter case is normalized in responses.
     */
    private const ENUM_KEYS = ['op', 'cmp', 'format', 'how', 'order', 'model_tier'];

    /**
     * Fields each operation may carry besides `op`.
     */
    private const OP_FIELDS = [
        'fetch' => ['url', 'format'],
        'parent' => ['node'],
        'document' => ['file_ref'],
        'extract' => ['path', 'row_key_field'],
        'filter' => ['field', 'cmp', 'value'],
        'map' => ['fields'],
        'merge' => ['with', 'on', 'how'],
        'sort' => ['field', 'order'],
        'limit' => ['count'],
        'unique' => ['field'],
        'llm' => ['instruction', 'input_fields', 'output_field', 'model_tier'],
    ];

    /**
     * Fields each operation requires, mirroring dsl.schema.json.
     */
    private const OP_REQUIRED_FIELDS = [
        'fetch' => ['url', 'format'],
        'parent' => ['node'],
        'document' => ['file_ref'],
        'extract' => ['path'],
        'filter' => ['field', 'cmp', 'value'],
        'map' => ['fields'],
        'merge' => ['with', 'on'],
        'sort' => ['field'],
        'limit' => ['count'],
        'unique' => ['field'],
        'llm' => ['instruction', 'input_fields', 'output_field', 'model_tier'],
    ];

    /**
     * One entry of `map`'s fields: an output column with either a source column or an expression.
     */
    private const MAP_FIELDS_ENTRY_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'new_col' => ['type' => 'STRING'],
            'source_col' => ['type' => 'STRING'],
            'expr' => ['type' => 'STRING'],
        ],
        'required' => ['new_col'],
    ];

    /**
     * Gemini schema for each step field.
     */
    private const FIELD_SCHEMAS = [
        'url' => ['type' => 'STRING'],
        'format' => ['type' => 'STRING', 'enum' => ['json', 'csv', 'html']],
        'node' => ['type' => 'STRING'],
        'file_ref' => ['type' => 'STRING'],
        'path' => ['type' => 'STRING'],
        'row_key_field' => ['type' => 'STRING'],
        'field' => ['type' => 'STRING'],
        'cmp' => ['type' => 'STRING', 'enum' => ['==', '!=', '>', '<', '>=', '<=', 'contains']],
        'value' => ['type' => 'STRING'],
        // An array of entries, because Gemini can't generate an object with arbitrary keys.
        'fields' => ['type' => 'ARRAY', 'items' => self::MAP_FIELDS_ENTRY_SCHEMA],
        'with' => ['type' => 'STRING'],
        'on' => ['type' => 'STRING'],
        'how' => ['type' => 'STRING', 'enum' => ['inner', 'left']],
        'order' => ['type' => 'STRING', 'enum' => ['asc', 'desc']],
        'count' => ['type' => 'INTEGER'],
        'instruction' => ['type' => 'STRING'],
        'input_fields' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'output_field' => ['type' => 'STRING'],
        'model_tier' => ['type' => 'STRING', 'enum' => ['cheap', 'standard', 'smart']],
    ];

    private int $lastTokensUsed = 0;

    /**
     * Token counts of the last call, split by kind, for cost estimates.
     *
     * @var array<string, int>
     */
    private array $lastUsageMetadata = [];

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $jsonSchema
     * @return array<int, array<string, mixed>>
     */
    public function generate(string $systemPrompt, string $userInput, array $jsonSchema, string $tier): array
    {
        // Pipeline requests (also an empty schema) use a hand-written schema, since Gemini lacks `oneOf` and `$ref`;
        // other schemas are translated generically.
        $isDslSchema = $jsonSchema === [] || isset($jsonSchema['definitions']);

        $model = $this->modelId($tier);

        $url = config('llm.gemini.base_url')."/models/{$model}:generateContent";

        $generationConfig = [
            'responseMimeType' => 'application/json',
            'responseSchema' => $isDslSchema ? $this->responseSchema() : $this->toGeminiSchema($jsonSchema),
        ];

        // Which levels exist differs between models, so none is sent unless configured.
        if (filled($thinkingLevel = config('llm.gemini.thinking_level'))) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $thinkingLevel];
        }

        try {
            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    // A header, because exception messages include the URL and would leak a query-string key.
                    'x-goog-api-key' => config('llm.gemini.api_key'),
                ])
                ->timeout(30)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->status() >= 500))
                ->post($url, [
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => [['parts' => [['text' => $userInput]]]],
                    'generationConfig' => $generationConfig,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new LlmUnavailableException($e->getMessage(), previous: $e);
        }

        $body = $response->json();
        $this->lastTokensUsed = $body['usageMetadata']['totalTokenCount'] ?? 0;
        $this->lastUsageMetadata = $body['usageMetadata'] ?? [];

        $text = $body['candidates'][0]['content']['parts'][0]['text']
            ?? throw new LlmUnavailableException('Gemini response had no candidate text.');

        $decoded = json_decode($text, associative: true);

        if (! is_array($decoded)) {
            throw new LlmUnavailableException('Gemini did not return a JSON array: '.$text);
        }

        return $isDslSchema ? $this->normalize($decoded) : $decoded;
    }

    /**
     * Translates a simple JSON Schema (objects, arrays, scalars) into Gemini's format.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $out = [];

        if (isset($schema['type'])) {
            $out['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['enum'])) {
            $out['enum'] = $schema['enum'];
        }

        if (isset($schema['properties'])) {
            $out['properties'] = array_map(
                fn (array $property) => $this->toGeminiSchema($property),
                $schema['properties'],
            );
            // Without it the model fills the fields in an order of its own and can pass over an optional one.
            $out['propertyOrdering'] = array_keys($schema['properties']);
        }

        if (isset($schema['items'])) {
            $out['items'] = $this->toGeminiSchema($schema['items']);
        }

        if (isset($schema['required'])) {
            $out['required'] = $schema['required'];
        }

        return $out;
    }

    /**
     * TODO: use Gemini's Batch API (cheaper, but asynchronous and may take hours, so it needs its own job
     * lifecycle). Until then supportsBatch() returns false.
     */
    public function generateBatch(string $systemPrompt, array $userInputs, array $jsonSchema, string $tier): array
    {
        throw new RuntimeException('GeminiAdapter does not support the batch endpoint yet (see the TODO above generateBatch()).');
    }

    public function supportsBatch(): bool
    {
        return false;
    }

    public function modelId(string $tier): string
    {
        return config("llm.gemini.models.{$tier}")
            ?? throw new InvalidArgumentException("Unknown LLM tier: \"{$tier}\".");
    }

    public function lastTokensUsed(): int
    {
        return $this->lastTokensUsed;
    }

    /**
     * @return array<string, int> raw usageMetadata from the last call
     *                            (promptTokenCount, candidatesTokenCount,
     *                            thoughtsTokenCount, totalTokenCount), or []
     *                            if no call has completed yet.
     */
    public function lastUsageMetadata(): array
    {
        return $this->lastUsageMetadata;
    }

    /**
     * One `anyOf` branch per operation, so the model can't mix fields between operations. The API rejects
     * `additionalProperties` here, so foreign fields are also stripped afterwards.
     *
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        $branches = [];

        foreach (self::OP_FIELDS as $op => $fields) {
            $properties = ['op' => ['type' => 'STRING', 'enum' => [$op]]];

            foreach ($fields as $field) {
                $properties[$field] = self::FIELD_SCHEMAS[$field];
            }

            $branches[] = [
                'type' => 'OBJECT',
                'properties' => $properties,
                'required' => ['op', ...self::OP_REQUIRED_FIELDS[$op]],
            ];
        }

        return [
            'type' => 'ARRAY',
            'items' => ['anyOf' => $branches],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $config
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $config): array
    {
        return array_map(function ($step) {
            if (! is_array($step)) {
                return $step;
            }

            foreach (self::ENUM_KEYS as $key) {
                if (isset($step[$key]) && is_string($step[$key])) {
                    $step[$key] = strtolower(trim($step[$key]));
                }
            }

            $step = $this->fixMapFieldsShape($step);

            return $this->stripFieldsForeignToOp($step);
        }, $config);
    }

    /**
     * Rebuilds `map`'s fields from the model's array into the object the DSL expects; a plain list of names becomes
     * an identity mapping.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function fixMapFieldsShape(array $step): array
    {
        if (($step['op'] ?? null) !== 'map' || ! isset($step['fields']) || ! is_array($step['fields'])) {
            return $step;
        }

        $fields = $step['fields'];

        if ($fields === []) {
            return $step;
        }

        $isListOfEntries = array_is_list($fields)
            && array_reduce($fields, fn ($ok, $v) => $ok && is_array($v) && isset($v['new_col']) && is_string($v['new_col']), true);

        if ($isListOfEntries) {
            $object = [];

            foreach ($fields as $entry) {
                $newCol = $entry['new_col'];

                if (isset($entry['source_col']) && $entry['source_col'] !== '') {
                    $object[$newCol] = $entry['source_col'];
                } elseif (isset($entry['expr']) && $entry['expr'] !== '') {
                    $object[$newCol] = ['expr' => $entry['expr']];
                } else {
                    $object[$newCol] = $newCol;
                }
            }

            $step['fields'] = $object;

            return $step;
        }

        $isListOfNames = array_is_list($fields)
            && array_reduce($fields, fn ($allStrings, $v) => $allStrings && is_string($v), true);

        if ($isListOfNames) {
            $step['fields'] = array_combine($fields, $fields);
        }

        return $step;
    }

    /**
     * Drops fields that don't belong to the step's operation, which would fail validation.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stripFieldsForeignToOp(array $step): array
    {
        $op = $step['op'] ?? null;

        if (! is_string($op) || ! isset(self::OP_FIELDS[$op])) {
            return $step;
        }

        $allowed = array_flip(['op', ...self::OP_FIELDS[$op]]);

        return array_intersect_key($step, $allowed);
    }
}
