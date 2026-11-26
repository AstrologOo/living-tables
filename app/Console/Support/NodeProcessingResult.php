<?php

namespace App\Console\Support;

use App\Modules\Graph\Models\Node;
use Throwable;

final class NodeProcessingResult
{
    private function __construct(
        public readonly ?Node $node,
        public readonly bool $succeeded,
        public readonly ?Throwable $failure,
    ) {}

    public static function nothingReady(): self
    {
        return new self(null, true, null);
    }

    public static function succeeded(Node $node): self
    {
        return new self($node, true, null);
    }

    public static function failed(Node $node, Throwable $e): self
    {
        return new self($node, false, $e);
    }
}
