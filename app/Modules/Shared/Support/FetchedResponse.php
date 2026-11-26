<?php

namespace App\Modules\Shared\Support;

/**
 * @param  array<string, array<int, string>>  $headers
 */
final class FetchedResponse
{
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
