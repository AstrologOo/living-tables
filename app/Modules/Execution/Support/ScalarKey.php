<?php

namespace App\Modules\Execution\Support;

/**
 * Normalizes scalars to strings for comparison: PHP's `==` treats `true == "false"` as true.
 */
class ScalarKey
{
    public static function normalize(mixed $value): string
    {
        return match (true) {
            $value === null => "\0null",
            is_bool($value) => $value ? '1' : '0',
            // Makes the strings "true" and "false" match real booleans.
            is_string($value) && strtolower($value) === 'true' => '1',
            is_string($value) && strtolower($value) === 'false' => '0',
            default => (string) $value,
        };
    }
}
