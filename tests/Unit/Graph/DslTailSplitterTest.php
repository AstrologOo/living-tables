<?php

use App\Modules\Graph\Support\DslTailSplitter;

it('splits a trailing sort/unique/limit run off the executable config', function () {
    $result = DslTailSplitter::split([
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
        ['op' => 'limit', 'count' => 5],
        ['op' => 'unique', 'field' => 'sku'],
        ['op' => 'sort', 'field' => 'price', 'order' => 'desc'],
    ]);

    expect($result['executable'])->toBe([
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ])
        ->and($result['sort_field'])->toBe('price')
        ->and($result['sort_order'])->toBe('desc')
        ->and($result['unique_field'])->toBe('sku')
        ->and($result['limit_count'])->toBe(5);
});

it('defaults sort_order to asc when a sort step omits it', function () {
    $result = DslTailSplitter::split([['op' => 'sort', 'field' => 'price']]);

    expect($result['sort_order'])->toBe('asc');
});

it('returns an empty tail (all criteria null) when nothing trails', function () {
    $config = [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ];

    $result = DslTailSplitter::split($config);

    expect($result['executable'])->toBe($config)
        ->and($result['sort_field'])->toBeNull()
        ->and($result['unique_field'])->toBeNull()
        ->and($result['limit_count'])->toBeNull();
});

it('treats a whole config of only tail ops as an empty executable pipeline', function () {
    $result = DslTailSplitter::split([
        ['op' => 'sort', 'field' => 'price'],
        ['op' => 'limit', 'count' => 3],
    ]);

    expect($result['executable'])->toBe([])
        ->and($result['sort_field'])->toBe('price')
        ->and($result['limit_count'])->toBe(3);
});

it('leaves a non-list dsl_config completely untouched, keys and all', function () {
    // A non-list must keep its keys.
    $config = ['format' => 'json', 'url' => 'https://example.com'];

    $result = DslTailSplitter::split($config);

    expect($result['executable'])->toBe($config)
        ->and($result['sort_field'])->toBeNull()
        ->and($result['unique_field'])->toBeNull()
        ->and($result['limit_count'])->toBeNull();
});

it('hasCriteria is true when any of sort/unique/limit is set', function () {
    $none = ['sort_field' => null, 'sort_order' => null, 'unique_field' => null, 'limit_count' => null];

    expect(DslTailSplitter::hasCriteria($none))->toBeFalse()
        ->and(DslTailSplitter::hasCriteria([...$none, 'unique_field' => 'sku']))->toBeTrue()
        ->and(DslTailSplitter::hasCriteria([...$none, 'limit_count' => 5]))->toBeTrue();
});
