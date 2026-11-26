<?php

use App\Modules\Graph\Models\LlmRowCache;
use App\Modules\Graph\Providers\GraphLlmCacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns nothing for keys with no cached entry', function () {
    $repo = new GraphLlmCacheRepository;

    expect($repo->getMany([str_repeat('a', 64)]))->toBe([]);
});

it('round-trips a value written with putMany', function () {
    $repo = new GraphLlmCacheRepository;
    $key = str_repeat('a', 64);

    $repo->putMany([$key => 'positive']);

    expect($repo->getMany([$key]))->toBe([$key => 'positive'])
        ->and(LlmRowCache::query()->count())->toBe(1);
});

it('only returns the requested keys among several stored', function () {
    $repo = new GraphLlmCacheRepository;
    $keyA = str_repeat('a', 64);
    $keyB = str_repeat('b', 64);

    $repo->putMany([$keyA => 'x', $keyB => 'y']);

    expect($repo->getMany([$keyA]))->toBe([$keyA => 'x']);
});

it('does not error or overwrite when putMany repeats an existing key', function () {
    $repo = new GraphLlmCacheRepository;
    $key = str_repeat('a', 64);

    $repo->putMany([$key => 'first']);
    $repo->putMany([$key => 'first']);

    expect(LlmRowCache::query()->count())->toBe(1)
        ->and($repo->getMany([$key]))->toBe([$key => 'first']);
});
