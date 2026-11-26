<?php

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Exceptions\TooManyRedirectsException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Requests user-supplied URLs with a time budget, a size cap, and redirects followed by hand: every hop passes
 * SafeUrlGuard and connects to the checked address. Uses curl, as Guzzle's stream handler can't pin addresses or
 * bound the total time.
 */
class SafeHttpFetcher
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SafeUrlGuard $guard,
        // For the whole redirect chain, not per hop.
        private readonly int $totalTimeoutSeconds = 30,
        private readonly int $maxRedirects = 5,
        private readonly int $maxResponseBytes = 10_485_760, // 10 MiB
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function get(string $url): string
    {
        return $this->fetch($url)->body;
    }

    /**
     * Like get(), but also returns the response headers.
     */
    public function getWithHeaders(string $url): FetchedResponse
    {
        return $this->fetch($url);
    }

    private function fetch(string $url): FetchedResponse
    {
        $current = $url;
        $deadline = microtime(true) + $this->totalTimeoutSeconds;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $address = $this->guard->assertAllowed($current);

            $remaining = round($deadline - microtime(true), 3);

            // Below a millisecond curl would read the timeout as "none".
            if ($remaining < 0.001) {
                throw new ConnectionException(
                    "Fetching \"{$url}\" exceeded the {$this->totalTimeoutSeconds}s budget."
                );
            }

            $response = $this->send($current, $address, $remaining);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '' || $location === null) {
                    $response->throw();
                }

                $current = $this->resolveRedirectTarget($current, $location);

                continue;
            }

            $response->throw();

            return new FetchedResponse($this->readBounded($response), $response->headers());
        }

        throw new TooManyRedirectsException("Exceeded {$this->maxRedirects} redirects fetching \"{$url}\".");
    }

    private function send(string $url, string $address, float $timeoutSeconds): Response
    {
        $sink = null;
        $curl = new CurlHandler;

        try {
            return $this->http
                ->timeout($timeoutSeconds)
                ->connectTimeout(min($this->connectTimeoutSeconds, $timeoutSeconds))
                ->withOptions([
                    'allow_redirects' => false,
                    // A proxy would resolve the hostname itself, ignoring
                    // the pin — including one picked up from HTTP_PROXY.
                    'proxy' => '',
                    ...$this->pinOptions($url, $address),
                ])
                // The innermost handler, skipped by Http::fake(): the size cap must apply during the transfer and count
                // decompressed bytes.
                ->setHandler(function (RequestInterface $request, array $options) use ($curl, &$sink) {
                    $sink = new BoundedSink(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')), $this->maxResponseBytes);

                    return $curl($request, [...$options, 'sink' => $sink]);
                })
                ->get($url);
        } catch (Throwable $e) {
            // curl reports a refused write only as a generic error.
            if ($sink?->exceeded()) {
                throw $this->tooLarge();
            }

            throw $e;
        }
    }

    /**
     * Pins the host to the checked address; Host, SNI and certificate checks still use the name. IP literals need no
     * pin.
     *
     * @return array<string, mixed>
     */
    private function pinOptions(string $url, string $address): array
    {
        $uri = new Uri($url);
        $host = $uri->getHost();

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $target = str_contains($address, ':') ? "[{$address}]" : $address;

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$target}"]]];
    }

    private function readBounded(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();

        // A faked response's stream may not be at the start.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            if (strlen($buffer) > $this->maxResponseBytes) {
                throw $this->tooLarge();
            }
        }

        return $buffer;
    }

    private function tooLarge(): ResponseTooLargeException
    {
        return new ResponseTooLargeException(
            "Response exceeded the {$this->maxResponseBytes}-byte limit."
        );
    }

    /**
     * Resolves a Location value against the current URL (RFC 3986).
     */
    private function resolveRedirectTarget(string $base, string $location): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($location));
        } catch (MalformedUriException) {
            throw new SsrfBlockedException("Refusing to follow a malformed redirect target \"{$location}\".");
        }
    }
}
