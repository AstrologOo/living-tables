<?php

use App\Modules\Intake\DslValidator;
use App\Modules\Intake\Exceptions\DslValidationException;

it('accepts a well-formed pipeline covering every operation', function () {
    $config = [
        ['op' => 'parent', 'node' => 'n1'],
        ['op' => 'fetch', 'url' => 'https://example.com/data', 'format' => 'json'],
        ['op' => 'document', 'file_ref' => 'f1'],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 5],
        ['op' => 'map', 'fields' => ['total' => ['expr' => 'price * qty'], 'name' => 'col_name']],
        ['op' => 'merge', 'with' => 'n2', 'on' => 'sku', 'how' => 'left'],
        ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['total'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
        // sort, unique and limit must come last.
        ['op' => 'sort', 'field' => 'total', 'order' => 'desc'],
        ['op' => 'limit', 'count' => 10],
        ['op' => 'unique', 'field' => 'sku'],
    ];

    (new DslValidator)->validate($config);
})->throwsNoExceptions();

// webhook_source is only valid in a config a user saves

it('rejects webhook_source by default, the same contract DslGenerator relies on', function () {
    expect(fn () => (new DslValidator)->validate([['op' => 'webhook_source']]))
        ->toThrow(DslValidationException::class);
});

it('accepts webhook_source only when allowWebhookSource is explicitly set', function () {
    (new DslValidator)->validate([
        ['op' => 'webhook_source'],
        ['op' => 'extract', 'path' => '$.items[*]'],
    ], allowWebhookSource: true);
})->throwsNoExceptions();

it('still rejects a genuinely malformed webhook_source step even with allowWebhookSource', function () {
    expect(fn () => (new DslValidator)->validate([
        ['op' => 'webhook_source', 'url' => 'not a real field for this op'],
    ], allowWebhookSource: true))->toThrow(DslValidationException::class);
});

// position of sort, unique and limit

it('rejects a sort step followed by a step that still needs to execute', function () {
    $config = [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'sort', 'field' => 'a'],
        ['op' => 'filter', 'field' => 'a', 'cmp' => '==', 'value' => 1],
    ];

    try {
        (new DslValidator)->validate($config);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect(implode('; ', $e->errors))->toContain('trailing block');
    }
});

it('accepts sort/unique/limit in any order as long as they trail everything else', function () {
    $config = [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'limit', 'count' => 10],
        ['op' => 'unique', 'field' => 'a'],
        ['op' => 'sort', 'field' => 'a'],
    ];

    (new DslValidator)->validate($config);
})->throwsNoExceptions();

it('rejects two sort steps in the trailing block', function () {
    $config = [
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'sort', 'field' => 'a'],
        ['op' => 'sort', 'field' => 'b'],
    ];

    try {
        (new DslValidator)->validate($config);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect(implode('; ', $e->errors))->toContain('only one "sort" step');
    }
});

it('rejects an unknown operation name', function () {
    expect(fn () => (new DslValidator)->validate([['op' => 'bogus']]))
        ->toThrow(DslValidationException::class);
});

it('accepts a fetch step with a well-formed pagination block, followed by extract "$[*]"', function () {
    $config = [
        [
            'op' => 'fetch',
            'url' => 'https://example.com/items',
            'format' => 'json',
            'pagination' => [
                'strategy' => 'cursor',
                'items_path' => '$.items[*]',
                'cursor_path' => '$.next',
                'max_pages' => 50,
            ],
        ],
        ['op' => 'extract', 'path' => '$[*]', 'row_key_field' => 'sku'],
    ];

    (new DslValidator)->validate($config);
})->throwsNoExceptions();

it('rejects a paginated fetch whose following extract path is not "$[*]"', function () {
    $config = [
        [
            'op' => 'fetch',
            'url' => 'https://example.com/items',
            'format' => 'json',
            'pagination' => ['strategy' => 'cursor', 'items_path' => '$.items[*]', 'cursor_path' => '$.next', 'max_pages' => 50],
        ],
        ['op' => 'extract', 'path' => '$.items[*]', 'row_key_field' => 'sku'],
    ];

    expect(fn () => (new DslValidator)->validate($config))->toThrow(DslValidationException::class);
});

it('rejects a pagination block missing cursor_path for the cursor strategy', function () {
    $config = [[
        'op' => 'fetch',
        'url' => 'https://example.com/items',
        'format' => 'json',
        'pagination' => ['strategy' => 'cursor', 'items_path' => '$.items[*]', 'max_pages' => 50],
    ]];

    expect(fn () => (new DslValidator)->validate($config))->toThrow(DslValidationException::class);
});

it('rejects a pagination block missing param for the page_param strategy', function () {
    $config = [[
        'op' => 'fetch',
        'url' => 'https://example.com/items',
        'format' => 'json',
        'pagination' => ['strategy' => 'page_param', 'items_path' => '$.items[*]', 'max_pages' => 50],
    ]];

    expect(fn () => (new DslValidator)->validate($config))->toThrow(DslValidationException::class);
});

it('rejects a pagination block with max_pages above the schema ceiling', function () {
    $config = [[
        'op' => 'fetch',
        'url' => 'https://example.com/items',
        'format' => 'json',
        'pagination' => ['strategy' => 'link_header', 'items_path' => '$.items[*]', 'max_pages' => 100000],
    ]];

    expect(fn () => (new DslValidator)->validate($config))->toThrow(DslValidationException::class);
});

it('rejects a step missing a required field', function () {
    expect(fn () => (new DslValidator)->validate([['op' => 'fetch', 'format' => 'json']]))
        ->toThrow(DslValidationException::class);
});

it('rejects an invalid enum value', function () {
    expect(fn () => (new DslValidator)->validate([
        ['op' => 'filter', 'field' => 'x', 'cmp' => 'nope', 'value' => 1],
    ]))->toThrow(DslValidationException::class);
});

it('rejects unknown extra properties on a step', function () {
    expect(fn () => (new DslValidator)->validate([
        ['op' => 'limit', 'count' => 1, 'unexpected' => true],
    ]))->toThrow(DslValidationException::class);
});

it('collects one message per violation', function () {
    try {
        (new DslValidator)->validate([['op' => 'fetch']]);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors)->not->toBeEmpty();
    }
});

dataset('minimal valid steps', [
    'parent' => [['op' => 'parent', 'node' => 'n1']],
    'fetch' => [['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'csv']],
    'document' => [['op' => 'document', 'file_ref' => 'f1']],
    'extract' => [['op' => 'extract', 'path' => '$.x']],
    'filter' => [['op' => 'filter', 'field' => 'a', 'cmp' => '==', 'value' => 1]],
    'map' => [['op' => 'map', 'fields' => ['a' => 'b']]],
    'merge' => [['op' => 'merge', 'with' => 'n2', 'on' => 'sku']],
    'sort' => [['op' => 'sort', 'field' => 'a']],
    'limit' => [['op' => 'limit', 'count' => 1]],
    'unique' => [['op' => 'unique', 'field' => 'a']],
    'llm' => [['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['a'], 'output_field' => 'b', 'model_tier' => 'standard']],
]);

it('accepts each operation with only its required fields', function (array $step) {
    (new DslValidator)->validate([$step]);
})->with('minimal valid steps')->throwsNoExceptions();

// resolveColumnReferences()

$columns = ['price' => 'col_price', 'qty' => 'col_qty', 'sku' => 'col_sku'];

it('translates simple field references to col_id', function () use ($columns) {
    $config = [
        ['op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 100],
        ['op' => 'sort', 'field' => 'qty'],
        ['op' => 'unique', 'field' => 'sku'],
        ['op' => 'merge', 'with' => 'n2', 'on' => 'sku'],
        ['op' => 'extract', 'path' => '$.x', 'row_key_field' => 'sku'],
        ['op' => 'map', 'fields' => ['renamed' => 'price']],
    ];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['field'])->toBe('col_price')
        ->and($resolved[1]['field'])->toBe('col_qty')
        ->and($resolved[2]['field'])->toBe('col_sku')
        ->and($resolved[3]['on'])->toBe('col_sku')
        ->and($resolved[4]['row_key_field'])->toBe('col_sku')
        ->and($resolved[5]['fields']['renamed'])->toBe('col_price');
});

it('leaves ops without column references untouched', function () use ($columns) {
    $config = [
        ['op' => 'parent', 'node' => 'n1'],
        ['op' => 'fetch', 'url' => 'https://x.test', 'format' => 'json'],
        ['op' => 'limit', 'count' => 5],
    ];

    expect((new DslValidator)->resolveColumnReferences($config, $columns))->toBe($config);
});

it('throws with the list of available columns when a name is unknown', function () use ($columns) {
    $config = [['op' => 'filter', 'field' => 'bogus', 'cmp' => '==', 'value' => 1]];

    try {
        (new DslValidator)->resolveColumnReferences($config, $columns);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors[0])->toContain('bogus')
            ->and($e->errors[0])->toContain('price')
            ->and($e->errors[0])->toContain('qty')
            ->and($e->errors[0])->toContain('sku');
    }
});

it('translates column identifiers inside expr, leaving operators/numbers/literals alone', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => [
        'total' => ['expr' => 'price * qty + 1'],
        'flag' => ['expr' => 'price > 0 and qty != null'],
    ]]];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['total']['expr'])->toBe('col_price * col_qty + 1')
        ->and($resolved[0]['fields']['flag']['expr'])->toBe('col_price > 0 and col_qty != null');
});

it('throws for an unresolvable identifier inside expr', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['x' => ['expr' => 'price * bogus']]]];

    expect(fn () => (new DslValidator)->resolveColumnReferences($config, $columns))
        ->toThrow(DslValidationException::class);
});

it('leaves a call to an allowed function (extract_number) untouched inside expr, not mistaken for a column', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['x' => ['expr' => 'extract_number(sku) === null ? -1 : extract_number(sku) * qty']]]];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['x']['expr'])->toBe('extract_number(col_sku) === null ? -1 : extract_number(col_sku) * col_qty');
});

it('leaves a call to is_fibonacci untouched inside expr', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['a' => ['expr' => 'is_fibonacci(qty)']]]];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['a']['expr'])->toBe('is_fibonacci(col_qty)');
});

it('resolves the column operand of the built-in "matches" operator, leaving the operator and pattern string alone', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['a' => ['expr' => "sku matches '/^[A-Z]+\\\\d+\$/'"]]]];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['a']['expr'])->toBe("col_sku matches '/^[A-Z]+\\\\d+\$/'");
});

it('leaves a call to regex_extract untouched inside expr, including the pattern string argument', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['b' => ['expr' => "regex_extract(sku, '/@([\\\\w.]+)/', 1)"]]]];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['b']['expr'])->toBe("regex_extract(col_sku, '/@([\\\\w.]+)/', 1)");
});

it('throws a distinct "unknown function" error for a hallucinated function call inside expr', function () use ($columns) {
    $config = [['op' => 'map', 'fields' => ['x' => ['expr' => 'parse_price(sku)']]]];

    try {
        (new DslValidator)->resolveColumnReferences($config, $columns);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors[0])->toContain('parse_price')
            ->and($e->errors[0])->toContain('extract_number');
    }
});

it('resolves llm.input_fields to col_id, leaving output_field as-is (it names a new column, not an existing one)', function () use ($columns) {
    $config = [['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['price', 'qty'], 'output_field' => 'sentiment', 'model_tier' => 'standard']];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['input_fields'])->toBe(['col_price', 'col_qty'])
        ->and($resolved[0]['output_field'])->toBe('sentiment');
});

it('lets a later step reference the column an earlier llm step just created', function () use ($columns) {
    $config = [
        ['op' => 'llm', 'instruction' => 'x', 'input_fields' => ['price'], 'output_field' => 'sentiment', 'model_tier' => 'standard'],
        ['op' => 'filter', 'field' => 'sentiment', 'cmp' => '==', 'value' => 'positive'],
    ];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[1]['field'])->toBe('sentiment');
});

it('lets a later step reference the column an earlier map step just created (regression)', function () use ($columns) {
    // A column created by `map` can be used by later steps.
    $config = [
        ['op' => 'map', 'fields' => ['total' => ['expr' => 'price * qty']]],
        ['op' => 'filter', 'field' => 'total', 'cmp' => '>', 'value' => 100],
    ];

    $resolved = (new DslValidator)->resolveColumnReferences($config, $columns);

    expect($resolved[0]['fields']['total']['expr'])->toBe('col_price * col_qty')
        ->and($resolved[1]['field'])->toBe('total');
});

// error messages

it('gives a precise per-op message for a missing required field, not the raw oneOf dump', function () {
    try {
        (new DslValidator)->validate([['op' => 'filter', 'field' => 'price', 'value' => 100]]); // missing cmp
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors)->toBe(['Step 0 ("filter"): missing required field(s): cmp.']);
    }
});

it('names a field foreign to the op instead of dumping every branch', function () {
    try {
        // "with" belongs to merge, not filter.
        (new DslValidator)->validate([[
            'op' => 'filter', 'field' => 'price', 'cmp' => '>', 'value' => 100, 'with' => 'n2',
        ]]);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors)->toBe(['Step 0 ("filter"): field(s) not valid for this op: with.']);
    }
});

it('falls back to the raw schema error for a step whose op is not recognized', function () {
    try {
        (new DslValidator)->validate([['op' => 'bogus_op']]);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect($e->errors)->not->toBe([])
            ->and(implode('; ', $e->errors))->not->toContain('Step 0 ("bogus_op")');
    }
});

it('flags map.fields given as an array instead of an object', function () {
    try {
        (new DslValidator)->validate([['op' => 'map', 'fields' => ['sku', 'name']]]);
        test()->fail('Expected DslValidationException was not thrown.');
    } catch (DslValidationException $e) {
        expect(implode('; ', $e->errors))->toContain('"fields" must be a JSON object');
    }
});
