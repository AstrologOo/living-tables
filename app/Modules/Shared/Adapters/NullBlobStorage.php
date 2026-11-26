<?php

namespace App\Modules\Shared\Adapters;

use App\Modules\Shared\Contracts\BlobStorage;
use RuntimeException;

/**
 * Stub until a storage adapter exists.
 */
class NullBlobStorage implements BlobStorage
{
    public function put(string $key, string $data): string
    {
        throw new RuntimeException('No BlobStorage adapter configured.');
    }

    public function get(string $key): string
    {
        throw new RuntimeException('No BlobStorage adapter configured.');
    }
}
