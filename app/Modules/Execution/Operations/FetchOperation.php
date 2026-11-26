<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Contracts\StreamableSource;
use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use App\Modules\Execution\Support\CanonicalJson;
use App\Modules\Shared\Support\FetchedResponse;
use App\Modules\Shared\Support\LinkHeaderParser;
use App\Modules\Shared\Support\SafeHttpFetcher;
use Flow\JSONPath\JSONPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Source step: downloads a URL as a raw row, or pages through a JSON API and collects the items, dropping
 * identical ones. Preview reads one page; any page failing after retries fails the step.
 *
 * config: {url: string, format: 'json'|'csv'|'html', pagination?: {strategy, items_path, max_pages, ...}}
 */
class FetchOperation implements Operation, StreamableSource
{
    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * Stops a source that ignores pagination and keeps returning seen items, whatever stop_when says.
     */
    private const MAX_CONSECUTIVE_EMPTY_PAGES = 3;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $format = $config['format'] ?? 'json';
        $pagination = $config['pagination'] ?? null;

        if ($pagination === null) {
            $raw = $this->decode($this->fetchPage($config['url'])->body, $format);

            return RowBatch::fromRows([
                ['row_key' => null, 'data' => ['_raw' => $raw, '_format' => $format]],
            ], $input->columnsSchema());
        }

        if ($format !== 'json') {
            throw new DslException('fetch: "pagination" is only supported for format "json".');
        }

        $items = $this->paginate($config['url'], $pagination, $ctx);

        return RowBatch::fromRows([
            ['row_key' => null, 'data' => ['_raw' => $items, '_format' => 'json']],
        ], $input->columnsSchema());
    }

    /**
     * Same pagination as apply(), but yields one page at a time to keep memory flat.
     *
     * @param  array<string, mixed>  $config
     * @return iterable<int, RowBatch>
     */
    public function stream(array $config, ExecutionContext $ctx): iterable
    {
        $format = $config['format'] ?? 'json';
        $pagination = $config['pagination'] ?? null;

        if ($pagination === null) {
            $raw = $this->decode($this->fetchPage($config['url'])->body, $format);

            yield RowBatch::fromRows([
                ['row_key' => null, 'data' => ['_raw' => $raw, '_format' => $format]],
            ]);

            return;
        }

        if ($format !== 'json') {
            throw new DslException('fetch: "pagination" is only supported for format "json".');
        }

        yield from $this->streamPages($config['url'], $pagination, $ctx);
    }

    /**
     * Yields each page's new items, remembering only content hashes for deduplication.
     *
     * @param  array<string, mixed>  $pagination
     * @return iterable<int, RowBatch>
     */
    private function streamPages(string $baseUrl, array $pagination, ExecutionContext $ctx): iterable
    {
        $strategy = $pagination['strategy'];
        $itemsPath = $pagination['items_path'];
        $maxPages = $pagination['max_pages'];
        $maxRows = $pagination['max_rows'] ?? null;
        $delayMs = $pagination['delay_ms'] ?? 0;
        $param = $pagination['param'] ?? null;
        $cursorPath = $pagination['cursor_path'] ?? null;
        $stopWhen = $pagination['stop_when'] ?? (
            in_array($strategy, ['page_param', 'offset'], true) ? 'empty_page' : 'no_cursor'
        );

        $pageLimit = $ctx->wantsCompleteData() ? $maxPages : 1;

        $seenHashes = [];
        $totalRows = 0;
        $consecutiveEmptyPages = 0;
        $counter = $pagination['start'] ?? ($strategy === 'offset' ? 0 : 1);
        $nextUrl = null;

        for ($page = 0; $page < $pageLimit; $page++) {
            $url = match ($strategy) {
                'page_param', 'offset' => $this->withQueryParam($baseUrl, $param, $counter),
                default => $page === 0 ? $baseUrl : $nextUrl,
            };

            if ($url === null) {
                break; // No next cursor or link.
            }

            $response = $this->fetchPage($url);
            $decoded = json_decode($response->body, associative: true);
            $pageItems = (new JSONPath($decoded))->find($itemsPath)->getData();
            $hasItems = $pageItems !== [];

            $newItems = [];
            foreach ($pageItems as $item) {
                $key = hash('sha256', CanonicalJson::encode(is_array($item) ? $item : ['value' => $item]));

                if (isset($seenHashes[$key])) {
                    continue;
                }

                $seenHashes[$key] = true;
                $newItems[] = $item;
            }

            $consecutiveEmptyPages = $newItems === [] ? $consecutiveEmptyPages + 1 : 0;

            // Checked on every page: repeats must not bypass the budget, the limits or the lock refresh.
            $totalRows += count($newItems);
            $this->enforceRowLimits($ctx, $totalRows, $maxRows);
            $ctx->heartbeat($totalRows, $newItems);

            if ($newItems !== []) {
                yield RowBatch::fromRows([
                    ['row_key' => null, 'data' => ['_raw' => $newItems, '_format' => 'json']],
                ]);
            }

            $nextUrl = match ($strategy) {
                'cursor' => $this->nextCursorUrl($decoded, $cursorPath, $baseUrl, $param),
                'link_header' => LinkHeaderParser::next($response->header('Link')),
                default => null,
            };

            $counter = match ($strategy) {
                'offset' => $counter + count($pageItems),
                'page_param' => $counter + 1,
                default => $counter,
            };

            if ($stopWhen !== 'max_pages') {
                if ($stopWhen === 'empty_page' && ! $hasItems) {
                    break;
                }

                if ($stopWhen === 'no_cursor' && $nextUrl === null) {
                    break;
                }
            }

            if ($consecutiveEmptyPages >= self::MAX_CONSECUTIVE_EMPTY_PAGES) {
                break;
            }

            if ($page + 1 < $pageLimit && $delayMs > 0) {
                ($this->sleeper)($delayMs);
            }
        }
    }

    private function decode(string $body, string $format): mixed
    {
        return match ($format) {
            'json' => json_decode($body, associative: true),
            'csv', 'html' => $body,
            default => throw new DslException("Unsupported fetch format: {$format}"),
        };
    }

    /**
     * @param  array<string, mixed>  $pagination
     * @return array<int, mixed>
     */
    private function paginate(string $baseUrl, array $pagination, ExecutionContext $ctx): array
    {
        $strategy = $pagination['strategy'];
        $itemsPath = $pagination['items_path'];
        $maxPages = $pagination['max_pages'];
        $maxRows = $pagination['max_rows'] ?? null;
        $delayMs = $pagination['delay_ms'] ?? 0;
        $param = $pagination['param'] ?? null;
        $cursorPath = $pagination['cursor_path'] ?? null;
        $stopWhen = $pagination['stop_when'] ?? (
            in_array($strategy, ['page_param', 'offset'], true) ? 'empty_page' : 'no_cursor'
        );

        $pageLimit = $ctx->wantsCompleteData() ? $maxPages : 1;

        $items = [];
        $seenHashes = [];
        $consecutiveEmptyPages = 0;
        $counter = $pagination['start'] ?? ($strategy === 'offset' ? 0 : 1);
        $nextUrl = null;

        for ($page = 0; $page < $pageLimit; $page++) {
            $url = match ($strategy) {
                'page_param', 'offset' => $this->withQueryParam($baseUrl, $param, $counter),
                default => $page === 0 ? $baseUrl : $nextUrl,
            };

            if ($url === null) {
                break; // No next cursor or link.
            }

            $response = $this->fetchPage($url);
            $decoded = json_decode($response->body, associative: true);
            $pageItems = (new JSONPath($decoded))->find($itemsPath)->getData();
            $hasItems = $pageItems !== [];

            if ($hasItems) {
                $items = [...$items, ...$pageItems];
            }

            // Only for the empty-pages guard; items are deduplicated at the end.
            $hasNewItems = false;
            foreach ($pageItems as $item) {
                $key = hash('sha256', CanonicalJson::encode(is_array($item) ? $item : ['value' => $item]));

                if (! isset($seenHashes[$key])) {
                    $seenHashes[$key] = true;
                    $hasNewItems = true;
                }
            }
            $consecutiveEmptyPages = $hasNewItems ? 0 : $consecutiveEmptyPages + 1;

            // Checked on every page, including ones that add nothing.
            $this->enforceRowLimits($ctx, count($items), $maxRows);
            $ctx->heartbeat(count($items), $items);

            $nextUrl = match ($strategy) {
                'cursor' => $this->nextCursorUrl($decoded, $cursorPath, $baseUrl, $param),
                'link_header' => LinkHeaderParser::next($response->header('Link')),
                default => null,
            };

            $counter = match ($strategy) {
                'offset' => $counter + count($pageItems),
                'page_param' => $counter + 1,
                default => $counter,
            };

            if ($stopWhen !== 'max_pages') {
                if ($stopWhen === 'empty_page' && ! $hasItems) {
                    break;
                }

                if ($stopWhen === 'no_cursor' && $nextUrl === null) {
                    break;
                }
            }

            if ($consecutiveEmptyPages >= self::MAX_CONSECUTIVE_EMPTY_PAGES) {
                break;
            }

            if ($page + 1 < $pageLimit && $delayMs > 0) {
                ($this->sleeper)($delayMs);
            }
        }

        return array_values($this->dedupe($items));
    }

    private function enforceRowLimits(ExecutionContext $ctx, int $totalRows, ?int $maxRows): void
    {
        if ($maxRows !== null && $totalRows > $maxRows) {
            throw new LimitExceededException(
                "fetch pagination: accumulated {$totalRows} rows, exceeding this step's max_rows ({$maxRows})."
            );
        }

        $limitReason = $ctx->usageLimiter()->exceededReason($ctx->currentUserId(), $totalRows);

        if ($limitReason !== null) {
            throw new LimitExceededException($limitReason);
        }
    }

    private function nextCursorUrl(mixed $decoded, ?string $cursorPath, string $baseUrl, ?string $param): ?string
    {
        $matches = (new JSONPath($decoded))->find($cursorPath)->getData();
        $cursorValue = $matches[0] ?? null;

        if ($cursorValue === null || $cursorValue === '') {
            return null;
        }

        if (is_string($cursorValue) && preg_match('#^https?://#i', $cursorValue)) {
            return $cursorValue;
        }

        return $this->withQueryParam($baseUrl, $param ?? 'cursor', (string) $cursorValue);
    }

    private function withQueryParam(string $url, string $param, int|string $value): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query[$param] = $value;
        $parts['query'] = http_build_query($query);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}{$host}{$port}{$path}?{$parts['query']}{$fragment}";
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function dedupe(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = hash('sha256', CanonicalJson::encode(is_array($item) ? $item : ['value' => $item]));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    private function fetchPage(string $url): FetchedResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->fetcher->getWithHeaders($url);
            } catch (Throwable $e) {
                $attempt++;

                if ($attempt >= self::MAX_ATTEMPTS || ! $this->isRetryable($e)) {
                    throw $e;
                }

                ($this->sleeper)((2 ** $attempt) * 500);
            }
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), self::RETRYABLE_STATUSES, true);
    }

    public function requiredInputColumns(array $config): array
    {
        return [];
    }
}
