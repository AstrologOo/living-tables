<?php

namespace App\Modules\Intake;

use App\Modules\Intake\Exceptions\DslValidationException;
use App\Modules\Shared\Contracts\LlmProvider;

/**
 * Turns a phrase and the parents' column schemas into a validated pipeline with columns resolved to col_id,
 * retrying once if validation fails. lastTokensUsed() reports the cost for the caller to record.
 */
class DslGenerator
{
    private int $tokensSpent = 0;

    public function __construct(
        private readonly LlmProvider $llm,
        private readonly DslValidator $validator = new DslValidator,
    ) {}

    public function lastTokensUsed(): int
    {
        return $this->tokensSpent;
    }

    /**
     * @param  array<int, array{node_id: string, columns_schema: array<string, array{name: string, type: string}>}>  $parentSchemas
     *                                                                                                                               col_id => {name, type}, per parent node
     * @param  ?string  $priorAttemptFeedback  what was wrong when an earlier config ran against real data
     * @return array<int, array<string, mixed>> a schema-valid DSL pipeline with column references already translated to col_id
     *
     * @throws DslValidationException if the model still fails to produce a valid DSL after one retry
     */
    public function generate(string $userPhrase, array $parentSchemas, ?string $priorAttemptFeedback = null): array
    {
        $this->tokensSpent = 0;

        $colIdByName = $this->buildColIdByName($parentSchemas);
        $userInput = $this->buildUserInput($userPhrase, $parentSchemas);

        if ($priorAttemptFeedback !== null) {
            $userInput .= "\n\n{$priorAttemptFeedback}";
        }

        try {
            return $this->attempt($userInput, $colIdByName);
        } catch (DslValidationException $e) {
            return $this->attempt($this->appendRetryContext($userInput, $e), $colIdByName);
        }
    }

    /**
     * @param  array<string, string>  $colIdByName
     * @return array<int, array<string, mixed>>
     */
    private function attempt(string $userInput, array $colIdByName): array
    {
        $config = $this->llm->generate($this->systemPrompt(), $userInput, $this->jsonSchema(), 'standard');
        $this->tokensSpent += $this->llm->lastTokensUsed();

        $this->validator->validate($config);

        return $this->validator->resolveColumnReferences($config, $colIdByName);
    }

    private function appendRetryContext(string $userInput, DslValidationException $e): string
    {
        return $userInput
            ."\n\nПредыдущий ответ не прошёл валидацию: ".implode('; ', $e->errors)
            ."\nИсправь и верни только валидный JSON по той же схеме, без пояснений.";
    }

    private function systemPrompt(): string
    {
        return file_get_contents(__DIR__.'/Prompts/dsl-system-prompt.md');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        return json_decode(file_get_contents(__DIR__.'/schema/dsl.schema.json'), associative: true);
    }

    /**
     * @param  array<int, array{node_id: string, columns_schema: array<string, array{name: string, type: string}>}>  $parentSchemas
     * @return array<string, string> column name => col_id, merged across all parents (a later parent wins on a name clash)
     */
    private function buildColIdByName(array $parentSchemas): array
    {
        $map = [];

        foreach ($parentSchemas as $parent) {
            foreach ($parent['columns_schema'] as $colId => $column) {
                $map[$column['name']] = $colId;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array{node_id: string, columns_schema: array<string, array{name: string, type: string}>}>  $parentSchemas
     */
    private function buildUserInput(string $userPhrase, array $parentSchemas): string
    {
        $lines = ["Фраза пользователя: {$userPhrase}"];

        foreach ($parentSchemas as $parent) {
            $lines[] = '';
            $lines[] = "Родитель, node id: {$parent['node_id']}";

            foreach ($parent['columns_schema'] as $column) {
                $lines[] = "  - {$column['name']}: {$column['type']}";
            }
        }

        return implode("\n", $lines);
    }
}
