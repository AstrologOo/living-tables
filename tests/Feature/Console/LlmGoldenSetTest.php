<?php

use App\Modules\Shared\Contracts\LlmProvider;
use Tests\Support\Fakes\RecordingLlmProvider;

it('reports the match rate and per-row detail against a fixed golden set', function () {
    $goldenSetFile = tempnam(sys_get_temp_dir(), 'golden-set').'.json';
    file_put_contents($goldenSetFile, json_encode([
        ['text' => 'Отличный товар!', 'expected' => 'positive'],
        ['text' => 'Ужасное качество.', 'expected' => 'negative'],
        ['text' => 'Обычная доставка.', 'expected' => 'neutral'],
    ]));

    // Row 2 mismatches on purpose.
    $llm = new RecordingLlmProvider([
        [
            ['id' => 0, 'value' => 'positive'],
            ['id' => 1, 'value' => 'negative'],
            ['id' => 2, 'value' => 'positive'],
        ],
    ]);
    $this->app->instance(LlmProvider::class, $llm);

    $outPath = tempnam(sys_get_temp_dir(), 'golden-report').'.md';

    $this->artisan('execution:llm-golden-set', ['--file' => $goldenSetFile, '--out' => $outPath])
        ->assertSuccessful();

    $report = file_get_contents($outPath);

    expect($report)->toContain('2/3 (66.7%)')
        ->toContain('Отличный товар!')
        ->toContain('| positive | positive | ✓ |')
        ->toContain('| neutral | positive | ✗ |');

    unlink($goldenSetFile);
    unlink($outPath);
});

it('fails cleanly when the golden-set file is missing or empty', function () {
    $this->artisan('execution:llm-golden-set', ['--file' => '/nonexistent/path.json'])
        ->assertFailed();
});
