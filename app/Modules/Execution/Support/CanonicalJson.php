<?php

namespace App\Modules\Execution\Support;

/**
 * Same encoding as Graph's CanonicalJson, copied because Execution must not depend on Graph.
 */
class CanonicalJson
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sortKeysRecursively($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
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
