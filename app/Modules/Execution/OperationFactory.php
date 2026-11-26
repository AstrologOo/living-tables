<?php

namespace App\Modules\Execution;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Execution\Operations\DocumentOperation;
use App\Modules\Execution\Operations\ExtractOperation;
use App\Modules\Execution\Operations\FetchOperation;
use App\Modules\Execution\Operations\FilterOperation;
use App\Modules\Execution\Operations\LlmOperation;
use App\Modules\Execution\Operations\MapOperation;
use App\Modules\Execution\Operations\MergeOperation;
use App\Modules\Execution\Operations\ParentOperation;
use App\Modules\Execution\Operations\WebhookSourceOperation;

class OperationFactory
{
    /**
     * sort, unique and limit are absent: they are applied when reading, not run as pipeline steps.
     *
     * @throws UnknownOperationException
     */
    public function make(string $op): Operation
    {
        $class = match ($op) {
            'fetch' => FetchOperation::class,
            'parent' => ParentOperation::class,
            'document' => DocumentOperation::class,
            'extract' => ExtractOperation::class,
            'filter' => FilterOperation::class,
            'map' => MapOperation::class,
            'merge' => MergeOperation::class,
            'llm' => LlmOperation::class,
            'webhook_source' => WebhookSourceOperation::class,
            default => throw new UnknownOperationException($op),
        };

        return app($class);
    }
}
