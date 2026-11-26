<?php

namespace App\Modules\Shared\Support;

/**
 * Extracts the `rel="next"` URL from a `Link` header (RFC 8288).
 */
class LinkHeaderParser
{
    public static function next(?string $linkHeader): ?string
    {
        if ($linkHeader === null) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="?next"?/i', trim($part), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
