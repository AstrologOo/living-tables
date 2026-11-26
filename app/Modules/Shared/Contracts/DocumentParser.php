<?php

namespace App\Modules\Shared\Contracts;

interface DocumentParser
{
    public function extractText(string $path): string;
}
