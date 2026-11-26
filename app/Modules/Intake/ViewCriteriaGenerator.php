<?php

namespace App\Modules\Intake;

use App\Modules\Intake\Exceptions\ViewCriteriaValidationException;
use App\Modules\Shared\Contracts\LlmProvider;

/**
 * Turns a phrase into validated view criteria for one node, with columns resolved to col_id, retrying once if
 * validation fails.
 */
class ViewCriteriaGenerator
{
    private int $tokensSpent = 0;

    public function __construct(
        private readonly LlmProvider $llm,
        private readonly ViewCriteriaValidator $validator = new ViewCriteriaValidator,
    ) {}

    public function lastTokensUsed(): int
    {
        return $this->tokensSpent;
    }

    /**
     * @param  array<string, array{name: string, type: string}>  $columnsSchema  col_id => {name, type}, this node's own
     * @param  ?string  $priorAttemptFeedback  what was wrong when earlier criteria ran against real data
     * @return array<string, mixed> a schema-valid criteria object with column references already translated to col_id
     *
     * @throws ViewCriteriaValidationException if the model still fails to produce valid criteria after one retry
     */
    public function generate(string $userPhrase, array $columnsSchema, ?string $priorAttemptFeedback = null): array
    {
        $this->tokensSpent = 0;

        $colIdByName = $this->buildColIdByName($columnsSchema);
        $userInput = $this->buildUserInput($userPhrase, $columnsSchema);

        if ($priorAttemptFeedback !== null) {
            $userInput .= "\n\n{$priorAttemptFeedback}";
        }

        try {
            return $this->attempt($userInput, $colIdByName);
        } catch (ViewCriteriaValidationException $e) {
            return $this->attempt($this->appendRetryContext($userInput, $e), $colIdByName);
        }
    }

    /**
     * @param  array<string, string>  $colIdByName
     * @return array<string, mixed>
     */
    private function attempt(string $userInput, array $colIdByName): array
    {
        $criteria = $this->llm->generate($this->systemPrompt(), $userInput, $this->jsonSchema(), 'cheap');
        $this->tokensSpent += $this->llm->lastTokensUsed();

        $this->validator->validate($criteria);

        return $this->validator->resolveColumnReferences($criteria, $colIdByName);
    }

    private function appendRetryContext(string $userInput, ViewCriteriaValidationException $e): string
    {
        return $userInput
            ."\n\nПредыдущий ответ не прошёл валидацию: ".implode('; ', $e->errors)
            ."\nИсправь и верни только валидный JSON по той же схеме, без пояснений.";
    }

    private function systemPrompt(): string
    {
        return file_get_contents(__DIR__.'/Prompts/view-criteria-system-prompt.md');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        return json_decode(file_get_contents(__DIR__.'/schema/view-criteria.schema.json'), associative: true);
    }

    /**
     * @param  array<string, array{name: string, type: string}>  $columnsSchema
     * @return array<string, string> column name => col_id
     */
    private function buildColIdByName(array $columnsSchema): array
    {
        $map = [];

        foreach ($columnsSchema as $colId => $column) {
            $map[$column['name']] = $colId;
        }

        return $map;
    }

    /**
     * @param  array<string, array{name: string, type: string}>  $columnsSchema
     */
    private function buildUserInput(string $userPhrase, array $columnsSchema): string
    {
        $lines = ["Фраза пользователя: {$userPhrase}", '', 'Колонки узла:'];

        foreach ($columnsSchema as $column) {
            $lines[] = "  - {$column['name']}: {$column['type']}";
        }

        return implode("\n", $lines);
    }
}
