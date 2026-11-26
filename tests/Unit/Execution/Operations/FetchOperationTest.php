<?php

use App\Modules\Execution\Contracts\ProgressReporter;
use App\Modules\Execution\Contracts\UsageLimiter;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\Operations\FetchOperation;
use App\Modules\Execution\RowBatch;
use App\Modules\Shared\Support\SafeHttpFetcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Tests\Support\Fakes\FakeSafeUrlGuard;
use Tests\Support\Fakes\FakeUsageLimiter;

function fakeFetcher(HttpFactory $http): SafeHttpFetcher
{
    // A permissive guard, as example.test never resolves.
    return new SafeHttpFetcher($http, new FakeSafeUrlGuard);
}

function noSleep(): callable
{
    return function (int $ms): void {};
}

it('fetches JSON and decodes it into the raw row', function () {
    $http = new HttpFactory;
    $http->fake(['*' => HttpFactory::response(json_encode(['a' => 1]), 200)]);

    $result = (new FetchOperation(fakeFetcher($http)))->apply(
        RowBatch::fromRows([]),
        ['url' => 'https://example.test/data', 'format' => 'json'],
        executionContext()
    );

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['_raw' => ['a' => 1], '_format' => 'json']],
    ]);
});

it('fetches html/csv as a raw string body', function () {
    $http = new HttpFactory;
    $http->fake(['*' => HttpFactory::response('<p>hi</p>', 200)]);

    $result = (new FetchOperation(fakeFetcher($http)))->apply(
        RowBatch::fromRows([]),
        ['url' => 'https://example.test/page', 'format' => 'html'],
        executionContext()
    );

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['_raw' => '<p>hi</p>', '_format' => 'html']],
    ]);
});

it('defaults to json format', function () {
    $http = new HttpFactory;
    $http->fake(['*' => HttpFactory::response('{"x":true}', 200)]);

    $result = (new FetchOperation(fakeFetcher($http)))->apply(RowBatch::fromRows([]), ['url' => 'https://example.test'], executionContext());

    expect($result->rows()[0]['data']['_format'])->toBe('json');
});

it('retries a transient failure before succeeding, then does not retry a non-retryable one', function () {
    $http = new HttpFactory;
    $http->fake(['https://example.test/flaky' => $http->sequence()
        ->push('', 503)
        ->push(json_encode(['ok' => true]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        ['url' => 'https://example.test/flaky', 'format' => 'json'],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe(['ok' => true]);
});

it('does not retry a non-retryable client error', function () {
    $http = new HttpFactory;
    $http->fake(['https://example.test/bad' => HttpFactory::response('', 400)]);

    expect(fn () => (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        ['url' => 'https://example.test/bad', 'format' => 'json'],
        executionContext()
    ))->toThrow(RequestException::class);

    $http->assertSentCount(1);
});

it('paginates via page_param, stopping on the first empty page', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
        'https://example.test/items?page=3' => HttpFactory::response(json_encode(['items' => []]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2]]);
});

it('paginates via offset, advancing by the previous page\'s actual item count', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?offset=0' => HttpFactory::response(json_encode(['items' => [['id' => 1], ['id' => 2]]]), 200),
        'https://example.test/items?offset=2' => HttpFactory::response(json_encode(['items' => [['id' => 3]]]), 200),
        'https://example.test/items?offset=3' => HttpFactory::response(json_encode(['items' => []]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'offset', 'param' => 'offset', 'start' => 0, 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2], ['id' => 3]]);
});

it('paginates via cursor, following a bare token through the param, until no_cursor', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items' => HttpFactory::response(json_encode(['items' => [['id' => 1]], 'next' => 'abc']), 200),
        'https://example.test/items?cursor=abc' => HttpFactory::response(json_encode(['items' => [['id' => 2]], 'next' => null]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'cursor', 'param' => 'cursor', 'cursor_path' => '$.next', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2]]);
});

it('paginates via cursor, following an absolute next URL as-is', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items' => HttpFactory::response(json_encode([
            'items' => [['id' => 1]], 'next' => 'https://example.test/items/page2',
        ]), 200),
        'https://example.test/items/page2' => HttpFactory::response(json_encode(['items' => [['id' => 2]], 'next' => null]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'cursor', 'cursor_path' => '$.next', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2]]);
});

it('paginates via the Link response header, stopping once it stops appearing', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items' => HttpFactory::response(
            json_encode(['items' => [['id' => 1]]]), 200, ['Link' => '<https://example.test/items?page=2>; rel="next"']
        ),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'link_header', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2]]);
});

it('never exceeds max_pages even when every page keeps returning items', function () {
    $http = new HttpFactory;
    $http->fake(fn ($request) => HttpFactory::response(json_encode(['items' => [['x' => 1]]]), 200));

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 3],
        ],
        executionContext()
    );

    expect($result->rows()[0]['data']['_raw'])->toHaveCount(1); // every page returns the same item -> deduped to one
});

it('throws LimitExceededException once accumulated rows exceed max_rows', function () {
    $http = new HttpFactory;
    $http->fake(['https://example.test/items?page=1' => HttpFactory::response(
        json_encode(['items' => [['id' => 1], ['id' => 2]]]), 200
    )]);

    expect(fn () => (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10, 'max_rows' => 1],
        ],
        executionContext()
    ))->toThrow(LimitExceededException::class);
});

it('checks the UsageLimiter after every page, not only once at the end', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
    ]);

    $ctx = executionContext(usageLimiter: new FakeUsageLimiter('over the limit'));

    expect(fn () => (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    ))->toThrow(LimitExceededException::class, 'over the limit');
});

it('stops after exactly one page when the run does not want complete data (preview)', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
    ]);

    $ctx = executionContext(isBackground: false);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    );

    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1]]);
});

it('calls heartbeat after every page, including one that adds no new rows', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => []]), 200),
    ]);

    $reporter = new class implements ProgressReporter
    {
        public array $received = [];

        public array $rowsReceived = [];

        public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
        {
            $this->received[] = $processed;
            $this->rowsReceived[] = $rowsSoFar;
        }
    };

    $ctx = executionContext();
    $ctx->setProgressReporter($reporter);

    (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    );

    // The empty page still sends a heartbeat before the loop stops.
    expect($reporter->received)->toBe([1, 1])
        ->and($reporter->rowsReceived)->toBe([[['id' => 1]], [['id' => 1]]]);
});

// three duplicate pages in a row stop pagination, whatever stop_when says

it('stops after 3 consecutive all-duplicate pages even with stop_when: max_pages and a high max_pages', function () {
    $http = new HttpFactory;
    // Every page returns the same item.
    $http->fake(fn ($request) => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200));

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => [
                'strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]',
                'max_pages' => 500, 'stop_when' => 'max_pages',
            ],
        ],
        executionContext()
    );

    // Page 1 is new and pages 2-4 repeat it, so it stops there.
    $http->assertSentCount(4);
    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1]]);
});

it('stream stops after 3 consecutive all-duplicate pages even with stop_when: max_pages', function () {
    $http = new HttpFactory;
    $http->fake(fn ($request) => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200));

    $batches = iterator_to_array((new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => [
                'strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]',
                'max_pages' => 500, 'stop_when' => 'max_pages',
            ],
        ],
        executionContext()
    ));

    $http->assertSentCount(4);
    expect($batches)->toHaveCount(1);
});

it('resets the consecutive-empty-page counter once a page adds new content again', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200), // dup
        'https://example.test/items?page=3' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200), // dup
        'https://example.test/items?page=4' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200), // new -> resets counter
        'https://example.test/items?page=5' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200), // dup
    ]);

    $result = (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => [
                'strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]',
                'max_pages' => 5, 'stop_when' => 'max_pages',
            ],
        ],
        executionContext()
    );

    // Only two repeats after the new item, so max_pages stops it.
    $http->assertSentCount(5);
    expect($result->rows()[0]['data']['_raw'])->toBe([['id' => 1], ['id' => 2]]);
});

// limits are checked even on pages that add nothing

it('still checks the UsageLimiter on a page that adds no new rows', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200), // dup, no new rows
    ]);

    // Over the limit from the second check on, which comes with a page of duplicates.
    $limiter = new class implements UsageLimiter
    {
        private int $calls = 0;

        public function exceededReason(?string $userId, int $rowsSoFar): ?string
        {
            $this->calls++;

            return $this->calls >= 2 ? 'over the limit' : null;
        }
    };

    $ctx = executionContext(usageLimiter: $limiter);

    expect(fn () => (new FetchOperation(fakeFetcher($http), noSleep()))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    ))->toThrow(LimitExceededException::class, 'over the limit');
});

it('rejects pagination for a non-json format', function () {
    $http = new HttpFactory;

    expect(fn () => (new FetchOperation(fakeFetcher($http)))->apply(
        RowBatch::fromRows([]),
        [
            'url' => 'https://example.test/items',
            'format' => 'html',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    ))->toThrow(InvalidArgumentException::class);
});

// stream(): one batch per page

it('stream yields exactly one batch when there is no pagination at all', function () {
    $http = new HttpFactory;
    $http->fake(['*' => HttpFactory::response(json_encode(['a' => 1]), 200)]);

    $batches = iterator_to_array((new FetchOperation(fakeFetcher($http)))->stream(
        ['url' => 'https://example.test/data', 'format' => 'json'],
        executionContext()
    ));

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->rows())->toBe([
            ['row_key' => null, 'data' => ['_raw' => ['a' => 1], '_format' => 'json']],
        ]);
});

it('stream yields one batch per page instead of accumulating them all', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
        'https://example.test/items?page=3' => HttpFactory::response(json_encode(['items' => []]), 200),
    ]);

    $batches = iterator_to_array((new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        executionContext()
    ));

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->rows()[0]['data']['_raw'])->toBe([['id' => 1]])
        ->and($batches[1]->rows()[0]['data']['_raw'])->toBe([['id' => 2]]);
});

it('stream dedupes identical items across pages without yielding an empty batch for an all-duplicate page', function () {
    $http = new HttpFactory;
    $http->fake(fn ($request) => HttpFactory::response(json_encode(['items' => [['x' => 1]]]), 200));

    $batches = iterator_to_array((new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 3],
        ],
        executionContext()
    ));

    // Every page returns the same item -> only page 1 yields a (one-item) batch.
    expect($batches)->toHaveCount(1)
        ->and($batches[0]->rows()[0]['data']['_raw'])->toBe([['x' => 1]]);
});

it('stream stops after exactly one page when the run does not want complete data (preview)', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
    ]);

    $ctx = executionContext(isBackground: false);

    $batches = iterator_to_array((new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    ));

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->rows()[0]['data']['_raw'])->toBe([['id' => 1]]);
});

it('stream throws LimitExceededException once the deduplicated total exceeds max_rows', function () {
    $http = new HttpFactory;
    $http->fake(['https://example.test/items?page=1' => HttpFactory::response(
        json_encode(['items' => [['id' => 1], ['id' => 2]]]), 200
    )]);

    $generator = (new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'items_path' => '$.items[*]', 'max_pages' => 10, 'max_rows' => 1],
        ],
        executionContext()
    );

    expect(fn () => iterator_to_array($generator))->toThrow(LimitExceededException::class);
});

it('stream calls heartbeat with just that page\'s new rows, not every row seen so far', function () {
    $http = new HttpFactory;
    $http->fake([
        'https://example.test/items?page=1' => HttpFactory::response(json_encode(['items' => [['id' => 1]]]), 200),
        'https://example.test/items?page=2' => HttpFactory::response(json_encode(['items' => [['id' => 2]]]), 200),
        'https://example.test/items?page=3' => HttpFactory::response(json_encode(['items' => []]), 200),
    ]);

    $reporter = new class implements ProgressReporter
    {
        public array $received = [];

        public array $rowsReceived = [];

        public function heartbeat(?int $processed = null, ?array $rowsSoFar = null): void
        {
            $this->received[] = $processed;
            $this->rowsReceived[] = $rowsSoFar;
        }
    };

    $ctx = executionContext();
    $ctx->setProgressReporter($reporter);

    iterator_to_array((new FetchOperation(fakeFetcher($http), noSleep()))->stream(
        [
            'url' => 'https://example.test/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'page_param', 'param' => 'page', 'start' => 1, 'items_path' => '$.items[*]', 'max_pages' => 10],
        ],
        $ctx
    ));

    // `processed` is a running total; rows are only the new ones, and an empty page still sends a heartbeat.
    expect($reporter->received)->toBe([1, 2, 2])
        ->and($reporter->rowsReceived)->toBe([[['id' => 1]], [['id' => 2]], []]);
});
