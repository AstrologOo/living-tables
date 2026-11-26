<?php

namespace App\Modules\Shared\Contracts;

interface BlobStorage
{
    public function put(string $key, string $data): string;

    public function get(string $key): string;
}
