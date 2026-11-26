<?php

namespace App\Modules\Shared\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Response sink with a byte limit: a short write makes curl abort an oversized download.
 */
class BoundedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $written = 0;

    private bool $exceeded = false;

    public function __construct(private StreamInterface $stream, private readonly int $maxBytes) {}

    public function write($string): int
    {
        if ($this->written + strlen($string) > $this->maxBytes) {
            $this->exceeded = true;

            return 0;
        }

        $this->written += strlen($string);

        return $this->stream->write($string);
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
