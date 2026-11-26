<?php

namespace App\Console\Commands;

use App\Console\Support\InMemoryLlmCacheRepository;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\Operations\LlmOperation;
use App\Modules\Execution\RowBatch;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Shared\Contracts\DocumentParser;
use App\Modules\Shared\Contracts\LlmProvider;
use Illuminate\Console\Command;

/**
 * Runs labelled rows through the real `llm` operation and reports the match rate, to track model drift. Skips
 * the persistent cache, which would replay old answers.
 */
class LlmGoldenSet extends Command
{
    protected $signature = 'execution:llm-golden-set
        {--file= : Path to a golden-set JSON file (defaults to app/Modules/Execution/dev/llm-golden-set.json)}
        {--out= : Report output path (defaults to storage/app/llm-golden-set-<timestamp>.md)}';

    protected $description = 'Run the golden set through the current LlmProvider via LlmOperation and report the match rate';

    private const INSTRUCTION = 'Определи тональность отзыва о товаре. '
        .'Ответь ровно одним словом на английском: positive, negative или neutral — без пояснений.';

    private const INPUT_FIELD = 'text';

    private const OUTPUT_FIELD = 'sentiment';

    private const MODEL_TIER = 'standard';

    public function handle(LlmProvider $llmProvider): int
    {
        $file = $this->option('file') ?: app_path('Modules/Execution/dev/llm-golden-set.json');
        $entries = $this->loadEntries($file);

        if ($entries === []) {
            $this->error("No golden-set entries found in {$file}.");

            return self::FAILURE;
        }

        $ctx = new ExecutionContext(
            new GraphParentDataProvider,
            $llmProvider,
            app(DocumentParser::class),
            new InMemoryLlmCacheRepository,
            new GraphUsageLimiter,
            new GraphRowStore,
        );

        $rows = array_map(
            fn (int $i, array $entry) => ['row_key' => (string) $i, 'data' => [self::INPUT_FIELD => $entry['text']]],
            array_keys($entries),
            $entries,
        );

        $this->info('Running '.count($entries).' golden-set rows through LlmOperation...');

        $tokensBefore = $ctx->tokensUsed();
        $result = (new LlmOperation)->apply(RowBatch::fromRows($rows), [
            'instruction' => self::INSTRUCTION,
            'input_fields' => [self::INPUT_FIELD],
            'output_field' => self::OUTPUT_FIELD,
            'model_tier' => self::MODEL_TIER,
        ], $ctx);
        $tokensSpent = $ctx->tokensUsed() - $tokensBefore;

        $outputRows = $result->rows();
        $comparisons = [];
        $matched = 0;

        foreach ($entries as $i => $entry) {
            $actual = $outputRows[$i]['data'][self::OUTPUT_FIELD] ?? null;
            $isMatch = $this->normalize($actual) === $this->normalize($entry['expected']);
            $matched += $isMatch ? 1 : 0;
            $comparisons[] = ['text' => $entry['text'], 'expected' => $entry['expected'], 'actual' => $actual, 'match' => $isMatch];
        }

        $total = count($entries);
        $rate = round($matched / $total * 100, 1);

        $outPath = $this->option('out') ?: storage_path('app/llm-golden-set-'.now()->format('Ymd-His').'.md');
        file_put_contents($outPath, $this->renderReport($comparisons, $matched, $total, $rate, $tokensSpent));

        $this->info("{$matched}/{$total} ({$rate}%) matched the reference label. Tokens spent: {$tokensSpent}. Report written to {$outPath}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{text: string, expected: string}>
     */
    private function loadEntries(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalize(mixed $value): ?string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : null;
    }

    /**
     * @param  array<int, array{text: string, expected: string, actual: mixed, match: bool}>  $comparisons
     */
    private function renderReport(array $comparisons, int $matched, int $total, float $rate, int $tokensSpent): string
    {
        $lines = [
            "# LLM golden set — {$matched}/{$total} ({$rate}%) matched the reference label",
            '',
            "Tokens spent: {$tokensSpent} (see intake:iterate-phrases for a cost estimate methodology — "
                .'this harness reports the vendor-agnostic total from ExecutionContext::tokensUsed(), '
                .'not an input/output split, since it goes through the LlmProvider port rather than a '
                .'concrete adapter).',
            '',
            '| # | Text | Expected | Actual | Match |',
            '|---|------|----------|--------|-------|',
        ];

        foreach ($comparisons as $index => $c) {
            $mark = $c['match'] ? '✓' : '✗';
            $actual = $c['actual'] ?? '_(null)_';
            $lines[] = "| {$index} | {$c['text']} | {$c['expected']} | {$actual} | {$mark} |";
        }

        return implode("\n", $lines);
    }
}
