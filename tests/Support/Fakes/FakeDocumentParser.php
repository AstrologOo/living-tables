<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Contracts\DocumentParser;

class FakeDocumentParser implements DocumentParser
{
    /**
     * @param  array<string, string>  $textsByPath
     */
    public function __construct(private readonly array $textsByPath = []) {}

    public function extractText(string $path): string
    {
        return $this->textsByPath[$path] ?? '';
    }
}
