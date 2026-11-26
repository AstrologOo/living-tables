<?php

namespace App\Modules\Shared\Adapters;

use App\Modules\Shared\Contracts\DocumentParser;
use RuntimeException;

/**
 * Stub until a parser adapter exists.
 */
class NullDocumentParser implements DocumentParser
{
    public function extractText(string $path): string
    {
        throw new RuntimeException('No DocumentParser adapter configured.');
    }
}
