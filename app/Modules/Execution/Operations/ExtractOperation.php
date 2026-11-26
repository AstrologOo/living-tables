<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use Flow\JSONPath\JSONPath;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Turns raw source data into rows, by JSONPath or, for HTML, a CSS selector. Rows without a known _format pass
 * through.
 *
 * config: {path: string, row_key_field?: col_id}, the field identifying a row across runs
 */
class ExtractOperation implements Operation
{
    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $path = $config['path'];
        $rowKeyField = $config['row_key_field'] ?? null;
        $rows = [];

        foreach ($input->rows() as $row) {
            $format = $row['data']['_format'] ?? null;
            $extracted = match ($format) {
                'json' => $this->extractJson($row['data']['_raw'], $path),
                'html' => $this->extractHtml($row['data']['_raw'], $path),
                default => [$row],
            };

            foreach ($extracted as $extractedRow) {
                $rows[] = $rowKeyField === null
                    ? $extractedRow
                    : [...$extractedRow, 'row_key' => $extractedRow['data'][$rowKeyField] ?? null];
            }
        }

        return $input->withRows($rows);
    }

    /**
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    private function extractJson(mixed $raw, string $path): array
    {
        $matches = (new JSONPath($raw))->find($path)->getData();

        return array_map(
            fn ($item) => ['row_key' => null, 'data' => is_array($item) ? $item : ['value' => $item]],
            $matches
        );
    }

    /**
     * @return array<int, array{row_key: ?string, data: array<string, mixed>}>
     */
    private function extractHtml(string $raw, string $selector): array
    {
        return (new Crawler($raw))->filter($selector)->each(
            fn (Crawler $node) => ['row_key' => null, 'data' => ['value' => trim($node->text())]]
        );
    }

    public function requiredInputColumns(array $config): array
    {
        // Reads only the internal _raw and _format keys, so there is nothing to check for drift.
        return [];
    }
}
