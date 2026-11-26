<?php

namespace App\Console\Commands;

use App\Modules\Intake\DslGenerator;
use App\Modules\Intake\Exceptions\DslValidationException;
use App\Modules\Shared\Adapters\Llm\GeminiAdapter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs sample phrases through DslGenerator, reports success rate and cost, and fails below 80% valid. Uses the
 * Gemini adapter directly to read its token usage.
 */
class IteratePhrases extends Command
{
    protected $signature = 'intake:iterate-phrases
        {--file= : Path to a newline-separated phrases file (defaults to app/Modules/Intake/dev/phrases.txt)}
        {--limit= : Only run the first N phrases}
        {--out= : Report output path (defaults to storage/app/intake-dry-run-<timestamp>.md)}';

    protected $description = 'Run sample user phrases through DslGenerator and report the success rate';

    /**
     * Gemini flash price per million tokens, for a cost estimate only; output includes thinking tokens.
     */
    private const INPUT_PRICE_PER_MILLION = 1.50;

    private const OUTPUT_PRICE_PER_MILLION = 9.00;

    public function handle(GeminiAdapter $adapter): int
    {
        $phrasesFile = $this->option('file') ?: app_path('Modules/Intake/dev/phrases.txt');
        $phrases = $this->loadPhrases($phrasesFile);

        if ($phrases === []) {
            $this->error("No phrases found in {$phrasesFile}.");

            return self::FAILURE;
        }

        $generator = new DslGenerator($adapter);
        $parentSchemas = $this->sampleParentSchemas();
        $results = [];
        $succeeded = 0;

        foreach ($phrases as $index => $phrase) {
            $this->line(($index + 1).'/'.count($phrases).': '.$phrase);

            $before = $adapter->lastUsageMetadata();

            try {
                $dsl = $generator->generate($phrase, $parentSchemas);
                $results[] = ['phrase' => $phrase, 'ok' => true, 'dsl' => $dsl, 'usage' => $this->usageSince($before, $adapter)];
                $succeeded++;
            } catch (DslValidationException $e) {
                $results[] = ['phrase' => $phrase, 'ok' => false, 'error' => implode('; ', $e->errors), 'usage' => $this->usageSince($before, $adapter)];
            } catch (Throwable $e) {
                $results[] = ['phrase' => $phrase, 'ok' => false, 'error' => $e->getMessage(), 'usage' => $this->usageSince($before, $adapter)];
            }
        }

        $total = count($phrases);
        $rate = round($succeeded / $total * 100, 1);

        $outPath = $this->option('out') ?: storage_path('app/intake-dry-run-'.now()->format('Ymd-His').'.md');
        file_put_contents($outPath, $this->renderReport($results, $succeeded, $total, $rate));

        $this->info("{$succeeded}/{$total} ({$rate}%) produced a valid DSL. Report written to {$outPath}.");

        if ($rate < 80.0) {
            $this->warn('Below the 80% threshold: the prompt needs another iteration.');
        }

        return $rate >= 80.0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function loadPhrases(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $lines = array_map('trim', file($path) ?: []);
        $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));

        if ($limit = $this->option('limit')) {
            $lines = array_slice($lines, 0, (int) $limit);
        }

        return $lines;
    }

    /**
     * Usage of the last call, or zeros if none completed; a retried first attempt isn't counted.
     *
     * @param  array<string, int>  $before
     * @return array<string, int>
     */
    private function usageSince(array $before, GeminiAdapter $adapter): array
    {
        $after = $adapter->lastUsageMetadata();

        return $after !== $before ? $after : [];
    }

    /**
     * One fixed parent table used for every phrase.
     *
     * @return array<int, array{node_id: string, columns_schema: array<string, array{name: string, type: string}>}>
     */
    private function sampleParentSchemas(): array
    {
        return [[
            'node_id' => '00000000-0000-0000-0000-000000000001',
            'columns_schema' => [
                'col_sku' => ['name' => 'артикул', 'type' => 'string'],
                'col_name' => ['name' => 'название', 'type' => 'string'],
                'col_price' => ['name' => 'цена', 'type' => 'number'],
                'col_qty' => ['name' => 'количество', 'type' => 'integer'],
                'col_category' => ['name' => 'категория', 'type' => 'string'],
                'col_status' => ['name' => 'статус', 'type' => 'string'],
                'col_rating' => ['name' => 'рейтинг', 'type' => 'number'],
                'col_created_at' => ['name' => 'дата создания', 'type' => 'date'],
                'col_stock' => ['name' => 'остаток', 'type' => 'integer'],
                'col_sum' => ['name' => 'сумма', 'type' => 'number'],
                'col_customer_id' => ['name' => 'id клиента', 'type' => 'string'],
                'col_email' => ['name' => 'email', 'type' => 'string'],
            ],
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function renderReport(array $results, int $succeeded, int $total, float $rate): string
    {
        $lines = ["# Intake dry run — {$succeeded}/{$total} ({$rate}%)", ''];
        $lines = [...$lines, ...$this->renderUsageSummary($results), ''];

        foreach ($results as $index => $result) {
            $lines[] = '## '.($index + 1).'. '.$result['phrase'];

            if ($result['ok']) {
                $lines[] = '**OK**';
                $lines[] = '```json';
                $lines[] = json_encode($result['dsl'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $lines[] = '```';
            } else {
                $lines[] = '**FAILED**: '.$result['error'];
            }

            $lines[] = $this->renderUsageLine($result['usage']);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, string>
     */
    private function renderUsageSummary(array $results): array
    {
        $prompt = array_sum(array_column(array_column($results, 'usage'), 'promptTokenCount'));
        $output = array_sum(array_column(array_column($results, 'usage'), 'candidatesTokenCount'));
        $thoughts = array_sum(array_column(array_column($results, 'usage'), 'thoughtsTokenCount'));
        $cost = $this->estimateCost($prompt, $output + $thoughts);

        return [
            '## Токены и оценочная стоимость',
            '',
            "- input: {$prompt}, output: {$output}, thinking: {$thoughts}",
            '- оценка стоимости: $'.number_format($cost, 4)
                .' (по прайсу gemini-flash-latest на 2026-07-19: $1.50/M input, $9.00/M output+thinking)',
            '- **важно**: если у фразы был retry (§4.4), учтены только токены последней попытки — '
                .'реальный расход по таким фразам чуть выше, чем показано.',
        ];
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function renderUsageLine(array $usage): string
    {
        if ($usage === []) {
            return '_usage: n/a (запрос не завершился)_';
        }

        $prompt = $usage['promptTokenCount'] ?? 0;
        $output = $usage['candidatesTokenCount'] ?? 0;
        $thoughts = $usage['thoughtsTokenCount'] ?? 0;
        $cost = $this->estimateCost($prompt, $output + $thoughts);

        return "_usage: input={$prompt}, output={$output}, thinking={$thoughts}, ~\$".number_format($cost, 5).'_';
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return $inputTokens / 1_000_000 * self::INPUT_PRICE_PER_MILLION
            + $outputTokens / 1_000_000 * self::OUTPUT_PRICE_PER_MILLION;
    }
}
