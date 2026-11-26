<?php

namespace App\Modules\Graph\Services;

use App\Modules\Shared\Support\FetchedResponse;
use App\Modules\Shared\Support\LinkHeaderParser;
use App\Modules\Shared\Support\SafeHttpFetcher;

/**
 * Requests a user's source URL once and suggests a `fetch.pagination` block from the response. The suggestion is
 * a heuristic for the user to confirm.
 */
class SourceProbeService
{
    private const SAMPLE_ITEMS = 5;

    private const SAMPLE_STRING_LENGTH = 500;

    private const MAX_SAMPLE_BYTES = 65_536;

    private const COMMON_CURSOR_KEYS = ['next_cursor', 'nextCursor', 'next_page_token', 'nextPageToken', 'next', 'cursor'];

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * @return array{format: string, pagination: ?array<string, mixed>, sample: mixed}
     */
    public function probe(string $url): array
    {
        $response = $this->fetcher->getWithHeaders($url);
        $format = $this->guessFormat($response);

        if ($format !== 'json') {
            return ['format' => $format, 'pagination' => null, 'sample' => null];
        }

        $decoded = json_decode($response->body, associative: true);
        $collection = is_array($decoded) ? $this->findCollection($decoded) : null;

        return [
            'format' => 'json',
            'pagination' => $collection === null ? null : $this->detectPagination($decoded, $collection['path'], $response, $url),
            'sample' => $this->sample($decoded, $collection),
        ];
    }

    private function guessFormat(FetchedResponse $response): string
    {
        $contentType = $response->header('Content-Type') ?? '';

        return match (true) {
            str_contains($contentType, 'json') => 'json',
            str_contains($contentType, 'html') => 'html',
            str_contains($contentType, 'csv') => 'csv',
            default => 'json',
        };
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return ?array<string, mixed> a draft `pagination` block, or null if
     *                               nothing about this response looked
     *                               paginated
     */
    private function detectPagination(array $decoded, string $itemsPath, FetchedResponse $response, string $url): ?array
    {
        if (LinkHeaderParser::next($response->header('Link')) !== null) {
            return ['strategy' => 'link_header', 'items_path' => $itemsPath, 'max_pages' => 100];
        }

        $cursorKey = $this->guessCursorKey($decoded);

        if ($cursorKey !== null) {
            return [
                'strategy' => 'cursor',
                'items_path' => $itemsPath,
                'cursor_path' => "\$.{$cursorKey}",
                'param' => 'cursor',
                'max_pages' => 100,
            ];
        }

        $queryGuess = $this->guessFromQueryString($url);

        if ($queryGuess !== null) {
            return [...$queryGuess, 'items_path' => $itemsPath, 'max_pages' => 100];
        }

        return null;
    }

    /**
     * Finds the rows in a response: the response itself if it's a list, otherwise its longest top-level list,
     * preferring lists of objects.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return ?array{key: int|string|null, path: string} key is null when the response itself is the list
     */
    private function findCollection(array $decoded): ?array
    {
        if (array_is_list($decoded)) {
            return ['key' => null, 'path' => '$[*]'];
        }

        $best = null;
        $bestRank = null;

        foreach ($decoded as $key => $value) {
            if (! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            // Compared element by element: lists of objects first, then length.
            $rank = [(int) $this->isListOfObjects($value), count($value)];

            if ($bestRank === null || $rank > $bestRank) {
                [$best, $bestRank] = [$key, $rank];
            }
        }

        return $best === null ? null : ['key' => $best, 'path' => $this->itemsPath((string) $best)];
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function isListOfObjects(array $list): bool
    {
        foreach ($list as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return false;
            }
        }

        return $list !== [];
    }

    /** Bracket notation for keys dot notation can't express ("@graph", "a.b"). */
    private function itemsPath(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 ? "\$.{$key}[*]" : "\$['{$key}'][*]";
    }

    /**
     * The response's shape with only a few rows, trimmed further if still large, or nothing if that isn't enough.
     *
     * @param  ?array{key: int|string|null, path: string}  $collection
     */
    private function sample(mixed $decoded, ?array $collection): mixed
    {
        if ($collection !== null) {
            if ($collection['key'] === null) {
                $decoded = array_slice($decoded, 0, self::SAMPLE_ITEMS);
            } else {
                $decoded[$collection['key']] = array_slice($decoded[$collection['key']], 0, self::SAMPLE_ITEMS);
            }
        }

        if ($this->fitsSample($decoded)) {
            return $decoded;
        }

        $decoded = $this->shrink($decoded);

        return $this->fitsSample($decoded) ? $decoded : null;
    }

    private function fitsSample(mixed $value): bool
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && strlen($json) <= self::MAX_SAMPLE_BYTES;
    }

    private function shrink(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::SAMPLE_STRING_LENGTH) {
            return mb_substr($value, 0, self::SAMPLE_STRING_LENGTH).'…';
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $value = array_slice($value, 0, self::SAMPLE_ITEMS);
        }

        return array_map($this->shrink(...), $value);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function guessCursorKey(array $decoded): ?string
    {
        foreach (self::COMMON_CURSOR_KEYS as $key) {
            if (! empty($decoded[$key]) && is_string($decoded[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return ?array{strategy: string, param: string, start: int}
     */
    private function guessFromQueryString(string $url): ?array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        foreach (['page', 'p'] as $param) {
            if (isset($query[$param])) {
                return ['strategy' => 'page_param', 'param' => $param, 'start' => (int) $query[$param]];
            }
        }

        foreach (['offset', 'skip'] as $param) {
            if (isset($query[$param])) {
                return ['strategy' => 'offset', 'param' => $param, 'start' => (int) $query[$param]];
            }
        }

        return null;
    }
}
