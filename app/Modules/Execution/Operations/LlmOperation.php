<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use App\Modules\Execution\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fills one column per row using the model, in batches that send only the input fields. Answers are cached by
 * those fields; usage limits are checked first. A row the model skips gets null and is asked again next run.
 *
 * config: {instruction: string, input_fields: col_id[], output_field: col_id, model_tier: 'cheap'|'standard'|'smart'}
 */
class LlmOperation implements Operation
{
    private const BATCH_SIZE = 75;

    private const MAX_ATTEMPTS = 3;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly int $batchSize = self::BATCH_SIZE,
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $instruction = $config['instruction'];
        $inputFields = $config['input_fields'];
        $outputField = $config['output_field'];
        $tier = $config['model_tier'];

        $rows = $input->rows();

        if ($rows === []) {
            return $input;
        }

        $limitReason = $ctx->usageLimiter()->exceededReason($ctx->currentUserId(), count($rows));

        if ($limitReason !== null) {
            throw new LimitExceededException($limitReason);
        }

        $operationHash = $this->operationHash($instruction, $inputFields, $outputField);
        $modelId = $ctx->llm()->modelId($tier);

        $cacheKeyByIndex = [];
        foreach ($rows as $index => $row) {
            $cacheKeyByIndex[$index] = $this->cacheKey($row['data'], $inputFields, $operationHash, $modelId);
        }

        $cached = $ctx->llmCache()->getMany(array_values(array_unique($cacheKeyByIndex)));

        $values = [];
        $missIndexes = [];

        foreach ($cacheKeyByIndex as $index => $key) {
            if (array_key_exists($key, $cached)) {
                $values[$index] = $cached[$key];
            } else {
                $missIndexes[] = $index;
            }
        }

        $toCache = [];
        $chunks = array_chunk($missIndexes, $this->batchSize);

        $useBatchEndpoint = $chunks !== [] && $ctx->llm()->supportsBatch() && $ctx->isBackground();

        $chunkResults = $useBatchEndpoint
            ? $this->callBatchEndpoint($chunks, $rows, $inputFields, $instruction, $tier, $ctx)
            : $this->callSequentialChunks($chunks, $rows, $inputFields, $instruction, $tier, $ctx, $outputField, $values);

        foreach ($chunks as $chunkIndex => $batchIndexes) {
            $batchValues = $chunkResults[$chunkIndex];

            foreach ($batchIndexes as $index) {
                if (! array_key_exists($index, $batchValues)) {
                    Log::warning('llm operation: model dropped a row from its batch response', [
                        'row_key' => $rows[$index]['row_key'],
                        'output_field' => $outputField,
                    ]);

                    // Null in this result, but not cached: the next run asks again.
                    $values[$index] = null;

                    continue;
                }

                $values[$index] = $batchValues[$index];
                $toCache[$cacheKeyByIndex[$index]] = $batchValues[$index];
            }
        }

        if ($toCache !== []) {
            $ctx->llmCache()->putMany($toCache);
        }

        $outputRows = [];
        foreach ($rows as $index => $row) {
            $outputRows[] = [
                'row_key' => $row['row_key'],
                'data' => [...$row['data'], $outputField => $values[$index]],
            ];
        }

        return $input->withRows($outputRows);
    }

    /**
     * Sends chunks one by one, with a heartbeat after each to keep the lock.
     *
     * @param  array<int, array<int, int>>  $chunks
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<int, string>  $inputFields
     * @param  array<int, mixed>  $knownValues  original row index => value, cache hits so far
     * @return array<int, array<int, mixed>>
     */
    private function callSequentialChunks(
        array $chunks,
        array $rows,
        array $inputFields,
        string $instruction,
        string $tier,
        ExecutionContext $ctx,
        string $outputField,
        array $knownValues,
    ): array {
        $results = [];
        $processed = 0;

        foreach ($chunks as $chunk) {
            $chunkValues = $this->callSequential($chunk, $rows, $inputFields, $instruction, $tier, $ctx);
            $results[] = $chunkValues;
            $processed += count($chunk);

            foreach ($chunk as $index) {
                $knownValues[$index] = $chunkValues[$index] ?? null;
            }

            $ctx->heartbeat($processed, $this->rowsWithKnownValues($rows, $outputField, $knownValues));
        }

        return $results;
    }

    /**
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<int, mixed>  $knownValues  original row index => value
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    private function rowsWithKnownValues(array $rows, string $outputField, array $knownValues): array
    {
        $out = [];

        foreach ($knownValues as $index => $value) {
            $out[] = ['row_key' => $rows[$index]['row_key'], 'data' => [...$rows[$index]['data'], $outputField => $value]];
        }

        return $out;
    }

    /**
     * Sends one chunk to the model.
     *
     * @param  array<int, int>  $batchIndexes  original row indexes in this batch
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<int, string>  $inputFields
     * @return array<int, mixed> original row index => value, only for ids the model answered
     */
    private function callSequential(
        array $batchIndexes,
        array $rows,
        array $inputFields,
        string $instruction,
        string $tier,
        ExecutionContext $ctx,
    ): array {
        $localIdToIndex = array_values($batchIndexes);
        $userInput = $this->buildUserInput($instruction, $inputFields, $localIdToIndex, $rows);
        $jsonSchema = $this->responseJsonSchema();

        $response = $this->withRetries(function () use ($ctx, $userInput, $jsonSchema, $tier) {
            $result = $ctx->llm()->generate($this->systemPrompt(), $userInput, $jsonSchema, $tier);
            $ctx->addTokensUsed($ctx->llm()->lastTokensUsed());

            return $result;
        });

        return $this->mapResponseToIndexes($response, $localIdToIndex);
    }

    /**
     * Sends all chunks through the provider's batch endpoint; background runs only.
     *
     * @param  array<int, array<int, int>>  $chunks  each a list of original row indexes
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     * @param  array<int, string>  $inputFields
     * @return array<int, array<int, mixed>> one {original row index => value} map per chunk, same order as $chunks
     */
    private function callBatchEndpoint(
        array $chunks,
        array $rows,
        array $inputFields,
        string $instruction,
        string $tier,
        ExecutionContext $ctx,
    ): array {
        $localIdToIndexPerChunk = array_map(fn (array $chunk) => array_values($chunk), $chunks);
        $userInputs = array_map(
            fn (array $localIdToIndex) => $this->buildUserInput($instruction, $inputFields, $localIdToIndex, $rows),
            $localIdToIndexPerChunk,
        );
        $jsonSchema = $this->responseJsonSchema();

        $responses = $this->withRetries(function () use ($ctx, $userInputs, $jsonSchema, $tier) {
            $result = $ctx->llm()->generateBatch($this->systemPrompt(), $userInputs, $jsonSchema, $tier);
            $ctx->addTokensUsed($ctx->llm()->lastTokensUsed());

            return $result;
        });

        $ctx->heartbeat();

        return array_map(
            fn (array $response, array $localIdToIndex) => $this->mapResponseToIndexes($response, $localIdToIndex),
            $responses,
            $localIdToIndexPerChunk,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function withRetries(callable $call): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $call();
            } catch (Throwable $e) {
                $attempt++;

                if ($attempt >= $this->maxAttempts) {
                    throw new RuntimeException(
                        "llm operation: call failed after {$this->maxAttempts} attempts: {$e->getMessage()}",
                        previous: $e,
                    );
                }

                ($this->sleeper)((2 ** $attempt) * 500);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $response
     * @param  array<int, int>  $localIdToIndex
     * @return array<int, mixed>
     */
    private function mapResponseToIndexes(array $response, array $localIdToIndex): array
    {
        $values = [];

        foreach ($response as $entry) {
            if (! is_array($entry) || ! array_key_exists('value', $entry) || ! isset($entry['id'], $localIdToIndex[$entry['id']])) {
                continue;
            }

            $values[$localIdToIndex[$entry['id']]] = $entry['value'];
        }

        return $values;
    }

    /**
     * @param  array<int, string>  $inputFields
     * @param  array<int, int>  $localIdToIndex
     * @param  array<int, array{row_key: ?string, data: array<string, mixed>}>  $rows
     */
    private function buildUserInput(string $instruction, array $inputFields, array $localIdToIndex, array $rows): string
    {
        $lines = [$instruction, '', 'Строки (верни ровно один объект {"id","value"} на каждую):'];

        foreach ($localIdToIndex as $localId => $rowIndex) {
            $subset = array_intersect_key($rows[$rowIndex]['data'], array_flip($inputFields));
            $lines[] = "id={$localId}: ".CanonicalJson::encode($subset);
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(): string
    {
        return 'Ты размечаешь строки таблицы по инструкции пользователя. '
            .'Верни JSON-массив объектов {"id": <ordinal id строки>, "value": <результат>}, '
            .'ровно по одному объекту на каждую входную строку, без пояснений и без markdown-оград.';
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJsonSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'value' => ['type' => 'string'],
                ],
                'required' => ['id', 'value'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $inputFields
     */
    private function operationHash(string $instruction, array $inputFields, string $outputField): string
    {
        return hash('sha256', $instruction.'|'.implode(',', $inputFields).'|'.$outputField);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $inputFields
     */
    private function cacheKey(array $data, array $inputFields, string $operationHash, string $modelId): string
    {
        $subset = array_intersect_key($data, array_flip($inputFields));
        $rowHash = hash('sha256', CanonicalJson::encode($subset));

        return hash('sha256', $rowHash.$operationHash.$modelId);
    }

    public function requiredInputColumns(array $config): array
    {
        return $config['input_fields'];
    }
}
