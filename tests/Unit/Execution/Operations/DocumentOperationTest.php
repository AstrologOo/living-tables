<?php

use App\Modules\Execution\Operations\DocumentOperation;
use App\Modules\Execution\RowBatch;
use Tests\Support\Fakes\FakeDocumentParser;

it('extracts text from a document via the DocumentParser port', function () {
    $ctx = executionContext(documentParser: new FakeDocumentParser(['/tmp/f.pdf' => 'Hello world']));

    $result = (new DocumentOperation)->apply(RowBatch::fromRows([]), ['file_ref' => '/tmp/f.pdf'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => null, 'data' => ['_raw' => 'Hello world', '_format' => 'text']],
    ]);
});
