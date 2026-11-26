<?php

use App\Modules\Execution\Operations\WebhookSourceOperation;
use App\Modules\Execution\RowBatch;

it('produces a fetch-shaped raw/json row from the context\'s webhook payload', function () {
    $ctx = executionContext();
    $ctx->setWebhookPayload(['items' => [['sku' => 'A1']]]);

    $result = (new WebhookSourceOperation)->apply(RowBatch::fromRows([]), [], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['_raw' => ['items' => [['sku' => 'A1']]], '_format' => 'json']],
    ]);
});

it('produces a null _raw when no webhook payload has been received yet', function () {
    $result = (new WebhookSourceOperation)->apply(RowBatch::fromRows([]), [], executionContext());

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['_raw' => null, '_format' => 'json']],
    ]);
});
