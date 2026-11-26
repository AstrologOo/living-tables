<?php

namespace App\Modules\Intake;

use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\RowBatch;
use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Models\RunStat;
use App\Modules\Graph\Services\ColumnSchemaInferenceService;
use App\Modules\Intake\Exceptions\DslValidationException;
use RuntimeException;

/**
 * Previews a pipeline from a phrase or a hand-written config, saving only run stats. A generated config that
 * empties the data is sent back to the model.
 */
class NodePreviewService
{
    /**
     * Total generation attempts; the last result is returned even if still suspicious.
     */
    private const MAX_GENERATION_ATTEMPTS = 3;

    private const ENRICHMENT_OPS = ['fetch', 'document', 'map', 'llm', 'merge', 'webhook_source'];

    public function __construct(
        private readonly DslGenerator $generator,
        private readonly PreviewService $previews,
        private readonly ColumnSchemaInferenceService $schemaInference,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $dslConfig  required without a phrase
     * @return array{dsl_config: array<int, array<string, mixed>>, rows: array<int, mixed>, columns_schema: array<string, mixed>, inferred_columns_schema: array<string, mixed>, suggests_view: bool, warnings: array<int, string>}
     *
     * @throws DslValidationException if no valid config could be generated from the phrase
     * @throws RuntimeException|DslException|PipelineException if running the config failed
     */
    public function preview(Node $node, ?string $phrase, ?array $dslConfig): array
    {
        [$dslConfig, $batch, $warnings] = $phrase !== null
            ? $this->fromPhrase($node, $phrase)
            : [$dslConfig, ...$this->run($node, $dslConfig)];

        return [
            'dsl_config' => $dslConfig,
            'rows' => $batch->rows(),
            'columns_schema' => $batch->columnsSchema(),
            // A draft schema for the user to confirm.
            'inferred_columns_schema' => $this->schemaInference->infer($batch->rows()),
            // Without an enrichment step a view would be enough; this is only a hint.
            'suggests_view' => ! $this->hasEnrichmentStep($dslConfig),
            // Steps that turned a non-empty input into nothing.
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: RowBatch, 2: array<int, string>}
     */
    private function fromPhrase(Node $node, string $phrase): array
    {
        $parentSchemas = $node->parents()->get()->map(fn (Node $parent) => [
            'node_id' => $parent->id,
            'columns_schema' => $parent->columns_schema ?? [],
        ])->all();

        $feedback = null;

        for ($attempt = 1; ; $attempt++) {
            $startedAt = hrtime(true);

            try {
                $dslConfig = $this->generator->generate($phrase, $parentSchemas, $feedback);
            } catch (DslValidationException $e) {
                $this->recordGenerationStat($node, $startedAt, 'dsl_generation_failed');

                throw $e;
            }

            $this->recordGenerationStat($node, $startedAt, 'dsl_generation');

            [$batch, $warnings] = $this->run($node, $dslConfig);

            if ($warnings === [] || $attempt === self::MAX_GENERATION_ATTEMPTS) {
                return [$dslConfig, $batch, $warnings];
            }

            $feedback = $this->buildAnomalyFeedback($warnings);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $dslConfig
     * @return array{0: RowBatch, 1: array<int, string>} rows and warnings
     */
    private function run(Node $node, array $dslConfig): array
    {
        $batch = $this->previews->preview($node, $dslConfig);

        return [$batch, $this->emptyResultWarnings($this->previews->lastStepStats())];
    }

    /**
     * @param  array<int, array{op: string, rows_before: int, rows_after: int}>  $stepStats
     * @return array<int, string>
     */
    private function emptyResultWarnings(array $stepStats): array
    {
        $warnings = [];

        foreach ($stepStats as $index => $stat) {
            if ($stat['rows_before'] > 0 && $stat['rows_after'] === 0) {
                $warnings[] = "Шаг {$index} ({$stat['op']}) свёл {$stat['rows_before']} строк(и) к 0 — ".
                    'возможно, ошибка в условии, а не отсутствие подходящих данных.';
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function buildAnomalyFeedback(array $warnings): string
    {
        return "Предыдущая попытка дала пустой результат:\n".implode("\n", $warnings).
            "\nПересмотри условие — возможно, это сравнение по длине текста, регулярное выражение внутри filter ".
            "(filter.cmp только '==','!=','>','<','>=','<=','contains' — 'contains' ищет буквальную подстроку, не ".
            'regex), или ссылка на колонку, которой на самом деле нет в схеме родителя. Предложи другой подход.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $dslConfig
     */
    private function hasEnrichmentStep(array $dslConfig): bool
    {
        foreach ($dslConfig as $step) {
            if (in_array($step['op'] ?? null, self::ENRICHMENT_OPS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records generation cost even if the config never runs; it belongs to no version.
     */
    private function recordGenerationStat(Node $node, int $startedAt, string $status): void
    {
        RunStat::create([
            'user_id' => $node->user_id,
            'node_id' => $node->id,
            'version_id' => null,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'tokens_spent' => $this->generator->lastTokensUsed(),
            'rows_total' => 0,
            'rows_changed' => 0,
            'status' => $status,
        ]);
    }
}
