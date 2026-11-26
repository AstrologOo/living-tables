<?php

namespace App\Modules\Graph\Support;

/**
 * JSON with object keys sorted recursively, for stable hashing; list order is kept.
 */
final class CanonicalJson
{
    public static function encode(array $data): string
    {
        return json_encode(self::sortKeysRecursively($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeysRecursively(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(
            fn ($value) => is_array($value) ? self::sortKeysRecursively($value) : $value,
            $data
        );
    }
}
