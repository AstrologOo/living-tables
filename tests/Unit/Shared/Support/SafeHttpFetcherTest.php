<?php

use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Exceptions\TooManyRedirectsException;
use App\Modules\Shared\Support\SafeHttpFetcher;
use App\Modules\Shared\Support\SafeUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Tests\Support\Fakes\StubDnsSafeUrlGuard;

// IP literals need no DNS, so the real guard runs offline.

it('returns the response body', function () {
    $http = new HttpFactory;
    $http->fake(['http://93.184.216.34/data' => HttpFactory::response('hello', 200)]);

    $body = (new SafeHttpFetcher($http, new SafeUrlGuard))->get('http://93.184.216.34/data');

    expect($body)->toBe('hello');
});

it('throws on a non-2xx response', function () {
    $http = new HttpFactory;
    $http->fake(['http://93.184.216.34/data' => HttpFactory::response('nope', 500)]);

    (new SafeHttpFetcher($http, new SafeUrlGuard))->get('http://93.184.216.34/data');
})->throws(RequestException::class);

it('enforces a response size cap', function () {
    $http = new HttpFactory;
    $http->fake(['http://93.184.216.34/data' => HttpFactory::response(str_repeat('x', 1000), 200)]);

    (new SafeHttpFetcher($http, new SafeUrlGuard, maxResponseBytes: 10))->get('http://93.184.216.34/data');
})->throws(ResponseTooLargeException::class);

it('follows a redirect to an allowed target', function () {
    $http = new HttpFactory;
    $http->fake([
        'http://93.184.216.34/start' => HttpFactory::response('', 302, ['Location' => 'http://93.184.216.35/final']),
        'http://93.184.216.35/final' => HttpFactory::response('landed', 200),
    ]);

    $body = (new SafeHttpFetcher($http, new SafeUrlGuard))->get('http://93.184.216.34/start');

    expect($body)->toBe('landed');
});

it('blocks a redirect to a private address, even when the original URL is public', function () {
    $http = new HttpFactory;
    $http->fake([
        'http://93.184.216.34/start' => HttpFactory::response('', 302, ['Location' => 'http://169.254.169.254/final']),
    ]);

    (new SafeHttpFetcher($http, new SafeUrlGuard))->get('http://93.184.216.34/start');
})->throws(SsrfBlockedException::class);

it('getWithHeaders exposes response headers alongside the body', function () {
    $http = new HttpFactory;
    $http->fake(['http://93.184.216.34/data' => HttpFactory::response('hello', 200, ['Link' => '<http://93.184.216.34/next>; rel="next"'])]);

    $response = (new SafeHttpFetcher($http, new SafeUrlGuard))->getWithHeaders('http://93.184.216.34/data');

    expect($response->body)->toBe('hello')
        ->and($response->header('Link'))->toBe('<http://93.184.216.34/next>; rel="next"')
        ->and($response->header('link'))->toBe('<http://93.184.216.34/next>; rel="next"')
        ->and($response->header('X-Missing'))->toBeNull();
});

it('gives up after the configured number of redirects', function () {
    $http = new HttpFactory;
    $http->fake([
        'http://93.184.216.34/a' => HttpFactory::response('', 302, ['Location' => 'http://93.184.216.34/b']),
        'http://93.184.216.34/b' => HttpFactory::response('', 302, ['Location' => 'http://93.184.216.34/a']),
    ]);

    (new SafeHttpFetcher($http, new SafeUrlGuard, maxRedirects: 2))->get('http://93.184.216.34/a');
})->throws(TooManyRedirectsException::class);

/**
 * Fakes every request, recording each one's URL and Guzzle options.
 *
 * @param  array<string, mixed>  $responses  url => response
 * @param  array<int, array{url: string, options: array<string, mixed>}>  $sent
 */
function recordingHttp(array $responses, array &$sent): HttpFactory
{
    $http = new HttpFactory;
    $http->fake(function ($request, array $options) use ($responses, &$sent) {
        $sent[] = ['url' => $request->url(), 'options' => $options];

        return $responses[$request->url()];
    });

    return $http;
}

it('pins each hop to the address the guard checked, with total and connect timeouts', function () {
    $sent = [];
    $http = recordingHttp([
        'https://first.example/a' => HttpFactory::response('', 302, ['Location' => 'http://second.example:8080/b']),
        'http://second.example:8080/b' => HttpFactory::response('ok', 200),
    ], $sent);
    $guard = new StubDnsSafeUrlGuard([
        'first.example' => ['93.184.216.34'],
        'second.example' => ['2606:2800:220:1:248:1893:25c8:1946'],
    ]);

    (new SafeHttpFetcher($http, $guard))->get('https://first.example/a');

    expect($sent[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['first.example:443:93.184.216.34'])
        ->and($sent[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['second.example:8080:[2606:2800:220:1:248:1893:25c8:1946]'])
        ->and($sent[0]['options'])->toMatchArray(['connect_timeout' => 5, 'proxy' => ''])
        ->and($sent[0]['options']['timeout'])->toBeGreaterThan(29)->toBeLessThanOrEqual(30)
        ->and($sent[0]['options'])->not->toHaveKey('stream');
});

it('rejects a redirect to a hostname that resolves to a blocked address, without requesting it', function () {
    $sent = [];
    $http = recordingHttp([
        'http://first.example/' => HttpFactory::response('', 302, ['Location' => 'http://internal.example/']),
    ], $sent);
    $guard = new StubDnsSafeUrlGuard([
        'first.example' => ['93.184.216.34'],
        'internal.example' => ['10.0.0.5'],
    ]);

    expect(fn () => (new SafeHttpFetcher($http, $guard))->get('http://first.example/'))
        ->toThrow(SsrfBlockedException::class)
        ->and($sent)->toHaveCount(1);
});

it('resolves a redirect target against the current URL', function (string $location, string $expected) {
    $sent = [];
    $http = recordingHttp([
        'https://first.example/dir/page' => HttpFactory::response('', 302, ['Location' => $location]),
        $expected => HttpFactory::response('landed', 200),
    ], $sent);
    $guard = new StubDnsSafeUrlGuard([
        'first.example' => ['93.184.216.34'],
        'other.example' => ['93.184.216.35'],
    ]);

    expect((new SafeHttpFetcher($http, $guard))->get('https://first.example/dir/page'))->toBe('landed')
        ->and($sent[1]['url'])->toBe($expected);
})->with([
    'protocol-relative' => ['//other.example/path', 'https://other.example/path'],
    'root-relative' => ['/path', 'https://first.example/path'],
    'relative' => ['next', 'https://first.example/dir/next'],
]);

/** First hop takes $seconds, then redirects to a second one. */
function slowRedirectHttp(float $seconds, array &$sent): HttpFactory
{
    $http = new HttpFactory;
    $http->fake(function ($request, array $options) use ($seconds, &$sent) {
        $sent[] = $options;

        if (count($sent) === 1) {
            usleep((int) ($seconds * 1_000_000));

            return HttpFactory::response('', 302, ['Location' => 'http://93.184.216.35/next']);
        }

        return HttpFactory::response('landed', 200);
    });

    return $http;
}

it('gives each redirect hop only what is left of the overall budget', function () {
    $sent = [];
    $http = slowRedirectHttp(0.3, $sent);

    (new SafeHttpFetcher($http, new SafeUrlGuard, totalTimeoutSeconds: 3))->get('http://93.184.216.34/');

    expect($sent[0]['timeout'])->toBeGreaterThan(2.9)
        ->and($sent[1]['timeout'])->toBeLessThanOrEqual(2.7)
        ->and($sent[1]['connect_timeout'])->toBe($sent[1]['timeout']);
});

it('stops following redirects once the budget is spent', function () {
    $sent = [];
    $http = slowRedirectHttp(1.1, $sent);

    expect(fn () => (new SafeHttpFetcher($http, new SafeUrlGuard, totalTimeoutSeconds: 1))->get('http://93.184.216.34/'))
        ->toThrow(ConnectionException::class, 'budget')
        ->and($sent)->toHaveCount(1);
});
