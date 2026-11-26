<?php

use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Support\SafeHttpFetcher;
use App\Modules\Shared\Support\SafeUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

// Real curl against a local `php -S`, to test what Http::fake() skips. A ".invalid" host that answers proves the
// address was pinned; the stand-in guard allows 127.0.0.1.

beforeAll(function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $GLOBALS['transportPort'] = $port;
    $GLOBALS['transportServer'] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../../../Support/transport-server.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    for ($i = 0; $i < 100; $i++) {
        if ($connection = @fsockopen('127.0.0.1', $port)) {
            fclose($connection);

            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException('Local test server did not start.');
});

afterAll(function () {
    proc_terminate($GLOBALS['transportServer'], 9);
    proc_close($GLOBALS['transportServer']);
});

function loopbackFetcher(int $timeoutSeconds = 5, int $maxResponseBytes = 1_000_000): SafeHttpFetcher
{
    $guard = new class extends SafeUrlGuard
    {
        public function assertAllowed(string $url): string
        {
            return '127.0.0.1';
        }
    };

    return new SafeHttpFetcher(new HttpFactory, $guard, $timeoutSeconds, maxResponseBytes: $maxResponseBytes);
}

function transportUrl(string $path): string
{
    return "http://pinned.invalid:{$GLOBALS['transportPort']}{$path}";
}

it('connects to the pinned address while keeping the hostname in the Host header', function () {
    expect(loopbackFetcher()->get(transportUrl('/host')))
        ->toBe("pinned.invalid:{$GLOBALS['transportPort']}");
});

it('cuts off an oversized response', function (string $path) {
    loopbackFetcher(maxResponseBytes: 1000)->get(transportUrl($path));
})->with(['plain' => '/big', 'small on the wire, large once decompressed' => '/gzip-bomb'])
    ->throws(ResponseTooLargeException::class);

it('applies the timeout to the whole transfer, not to each read', function () {
    $startedAt = microtime(true);

    expect(fn () => loopbackFetcher(timeoutSeconds: 1)->get(transportUrl('/trickle')))
        ->toThrow(ConnectionException::class)
        ->and(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

it('shares the timeout across the whole redirect chain', function () {
    $startedAt = microtime(true);

    // 0.7s redirect, then a trickle: a per-hop timeout would run to 1.7s+.
    expect(fn () => loopbackFetcher(timeoutSeconds: 1)->get(transportUrl('/slow-redirect')))
        ->toThrow(ConnectionException::class)
        ->and(microtime(true) - $startedAt)->toBeLessThan(1.5);
});
