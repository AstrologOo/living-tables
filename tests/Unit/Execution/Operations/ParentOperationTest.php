<?php

use App\Modules\Execution\Operations\ParentOperation;
use App\Modules\Execution\RowBatch;
use Tests\Support\Fakes\FakeParentDataProvider;

it('returns the parent node payload, ignoring the input payload', function () {
    $parentPayload = RowBatch::fromRows([['row_key' => '1', 'data' => ['x' => 1]]]);
    $ctx = executionContext(new FakeParentDataProvider(['parent-1' => $parentPayload]));

    $result = (new ParentOperation)->apply(
        RowBatch::fromRows([['row_key' => 'ignored', 'data' => []]]),
        ['node' => 'parent-1'],
        $ctx
    );

    expect($result)->toBe($parentPayload);
});
