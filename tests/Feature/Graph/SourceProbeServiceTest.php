<?php

use App\Modules\Graph\Services\SourceProbeService;
use Flow\JSONPath\JSONPath;
use Illuminate\Support\Facades\Http;

it('detects link_header pagination and an items list under a common key', function () {
    Http::fake(['https://api.test/items' => Http::response(
        json_encode(['items' => [['id' => 1]]]), 200, ['Link' => '<https://api.test/items?page=2>; rel="next"']
    )]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items');

    expect($result['format'])->toBe('json')
        ->and($result['pagination'])->toBe(['strategy' => 'link_header', 'items_path' => '$.items[*]', 'max_pages' => 100])
        ->and($result['sample'])->toBe(['items' => [['id' => 1]]]);
});

it('detects cursor pagination from a common cursor field name', function () {
    Http::fake(['https://api.test/items' => Http::response(
        json_encode(['data' => [['id' => 1]], 'next_cursor' => 'abc123']), 200
    )]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items');

    expect($result['pagination'])->toBe([
        'strategy' => 'cursor',
        'items_path' => '$.data[*]',
        'cursor_path' => '$.next_cursor',
        'param' => 'cursor',
        'max_pages' => 100,
    ]);
});

it('detects page_param pagination from the URL\'s own query string', function () {
    Http::fake(['https://api.test/items?page=1' => Http::response(
        json_encode(['results' => [['id' => 1]], 'total_pages' => 5]), 200
    )]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items?page=1');

    expect($result['pagination'])->toBe([
        'strategy' => 'page_param',
        'param' => 'page',
        'start' => 1,
        'items_path' => '$.results[*]',
        'max_pages' => 100,
    ]);
});

it('detects offset pagination from the URL\'s own query string', function () {
    Http::fake(['https://api.test/items?offset=0' => Http::response(
        json_encode(['records' => [['id' => 1]]]), 200
    )]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items?offset=0');

    expect($result['pagination'])->toBe([
        'strategy' => 'offset',
        'param' => 'offset',
        'start' => 0,
        'items_path' => '$.records[*]',
        'max_pages' => 100,
    ]);
});

it('treats a bare top-level list as its own items path', function () {
    Http::fake(['https://api.test/items?page=1' => Http::response(json_encode([['id' => 1], ['id' => 2]]), 200)]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items?page=1');

    expect($result['pagination']['items_path'])->toBe('$[*]');
});

function probeJson(mixed $body, string $url = 'https://api.test/search'): array
{
    Http::fake([$url => Http::response(json_encode($body), 200, ['Content-Type' => 'application/json'])]);

    return app(SourceProbeService::class)->probe($url);
}

// Open Food Facts keeps megabytes of rows under "products".
it('finds a collection under an unlisted key and trims the sample to five rows', function () {
    $products = array_map(fn (int $i) => ['code' => (string) $i, 'name' => "Product {$i}"], range(1, 40));

    $result = probeJson(
        ['count' => 4000, 'page' => 1, 'products' => $products, 'page_size' => 40],
        'https://api.test/search?page=1',
    );

    expect($result['pagination'])->toBe([
        'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.products[*]', 'max_pages' => 100,
    ])
        ->and($result['sample']['products'])->toBe(array_slice($products, 0, 5))
        ->and($result['sample']['count'])->toBe(4000)
        ->and($result['sample']['page_size'])->toBe(40);
});

it('picks the longest list of objects, over longer lists of scalars', function () {
    $result = probeJson([
        'tags' => range(1, 50),
        'facets' => [['id' => 1]],
        'entries' => [['id' => 1], ['id' => 2]],
    ], 'https://api.test/search?page=1');

    expect($result['pagination']['items_path'])->toBe('$.entries[*]')
        ->and($result['sample']['entries'])->toHaveCount(2);
});

it('falls back to a list of scalars only when there is no list of objects', function () {
    $result = probeJson(['ok' => true, 'ids' => [1, 2], 'tags' => ['a', 'b', 'c']], 'https://api.test/search?page=1');

    expect($result['pagination']['items_path'])->toBe('$.tags[*]');
});

it('writes an items path JSONPath can resolve for unusual keys', function (string $key) {
    $body = [$key => [['id' => 7]]];

    $path = probeJson($body, 'https://api.test/search?page=1')['pagination']['items_path'];

    expect((new JSONPath($body))->find($path)->getData())->toBe([['id' => 7]]);
})->with(['@graph', 'a.b', 'my-items', 'товары']);

it('trims a top-level list to five rows', function () {
    expect(probeJson(range(1, 100))['sample'])->toBe([1, 2, 3, 4, 5]);
});

it('cuts nested lists and long strings when the sample is still too big', function () {
    $result = probeJson(['items' => [['text' => str_repeat('ж', 100_000), 'sizes' => range(1, 100)]]]);

    expect(mb_strlen($result['sample']['items'][0]['text']))->toBe(501)
        ->and($result['sample']['items'][0]['sizes'])->toBe([1, 2, 3, 4, 5]);
});

it('limits the sample of a response without any list, dropping it if it cannot fit', function () {
    $wide = array_fill_keys(array_map(fn (int $i) => "key{$i}", range(1, 5000)), str_repeat('x', 50));

    expect(probeJson($wide)['sample'])->toBeNull()
        ->and(probeJson(['note' => str_repeat('x', 100_000)], 'https://api.test/note')['sample']['note'])->toHaveLength(501);
});

it('returns no pagination guess when nothing about the response suggests it', function () {
    Http::fake(['https://api.test/items' => Http::response(json_encode(['items' => [['id' => 1]]]), 200)]);

    $result = app(SourceProbeService::class)->probe('https://api.test/items');

    expect($result['pagination'])->toBeNull();
});

it('returns no pagination guess when no list of rows is found anywhere', function () {
    Http::fake(['https://api.test/status' => Http::response(json_encode(['ok' => true]), 200)]);

    $result = app(SourceProbeService::class)->probe('https://api.test/status');

    expect($result['pagination'])->toBeNull();
});

it('never guesses pagination for a non-json response', function () {
    Http::fake(['https://api.test/page.html' => Http::response('<p>hi</p>', 200, ['Content-Type' => 'text/html'])]);

    $result = app(SourceProbeService::class)->probe('https://api.test/page.html');

    expect($result['format'])->toBe('html')
        ->and($result['pagination'])->toBeNull()
        ->and($result['sample'])->toBeNull();
});
