<?php

use Illuminate\Support\Facades\Http;

function fakeGeminiDslResponse(array $steps, int $promptTokens = 100, int $outputTokens = 20, int $thoughtsTokens = 5)
{
    return Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode($steps)]]]]],
        'usageMetadata' => [
            'promptTokenCount' => $promptTokens,
            'candidatesTokenCount' => $outputTokens,
            'thoughtsTokenCount' => $thoughtsTokens,
            'totalTokenCount' => $promptTokens + $outputTokens + $thoughtsTokens,
        ],
    ]);
}

it('reports per-phrase token usage and an estimated cost in the report file', function () {
    Http::fake(['*' => fakeGeminiDslResponse([
        ['op' => 'parent', 'node' => '00000000-0000-0000-0000-000000000001'],
        ['op' => 'limit', 'count' => 5],
    ])]);

    $phrasesFile = tempnam(sys_get_temp_dir(), 'phrases');
    file_put_contents($phrasesFile, "Покажи первые 5 товаров\n");
    $outPath = tempnam(sys_get_temp_dir(), 'report').'.md';

    $this->artisan('intake:iterate-phrases', ['--file' => $phrasesFile, '--out' => $outPath])
        ->assertSuccessful();

    $report = file_get_contents($outPath);

    expect($report)->toContain('1/1 (100%)')
        ->toContain('input=100, output=20, thinking=5')
        ->toContain('input: 100, output: 20, thinking: 5')
        ->toContain('оценка стоимости: $');

    unlink($phrasesFile);
    unlink($outPath);
});

it('marks usage as n/a when a phrase never gets a completed response', function () {
    Http::fake(['*' => Http::response(['candidates' => []])]);

    $phrasesFile = tempnam(sys_get_temp_dir(), 'phrases');
    file_put_contents($phrasesFile, "Любая фраза\n");
    $outPath = tempnam(sys_get_temp_dir(), 'report').'.md';

    $this->artisan('intake:iterate-phrases', ['--file' => $phrasesFile, '--out' => $outPath])
        ->assertFailed();

    expect(file_get_contents($outPath))->toContain('usage: n/a');

    unlink($phrasesFile);
    unlink($outPath);
});
