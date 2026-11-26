<?php

use App\Modules\Execution\Payload;

it('is a thin pointer exposing runId, columnsSchema, and rowCount', function () {
    $payload = Payload::fromRun('run-1', ['col_a' => ['name' => 'A', 'type' => 'string']], 3);

    expect($payload->runId())->toBe('run-1')
        ->and($payload->columnsSchema())->toBe(['col_a' => ['name' => 'A', 'type' => 'string']])
        ->and($payload->count())->toBe(3)
        ->and(count($payload))->toBe(3);
});

it('defaults to an empty columns schema', function () {
    $payload = Payload::fromRun('run-1', [], 0);

    expect($payload->columnsSchema())->toBe([])
        ->and($payload->count())->toBe(0);
});

it('defaults to empty step stats and exposes whatever is given', function () {
    $withoutStats = Payload::fromRun('run-1', [], 0);
    expect($withoutStats->stepStats())->toBe([]);

    $stats = [1 => ['op' => 'filter', 'rows_before' => 5, 'rows_after' => 0]];
    $withStats = Payload::fromRun('run-1', [], 0, $stats);
    expect($withStats->stepStats())->toBe($stats);
});
