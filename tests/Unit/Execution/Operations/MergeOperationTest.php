<?php

use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Operations\MergeOperation;
use App\Modules\Execution\RowBatch;
use Tests\Support\Fakes\FakeRowStore;

it('inner joins on the given field, keeping the left row_key', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'l1', 'data' => ['sku' => 'A', 'qty' => 5]],
        ['row_key' => 'l2', 'data' => ['sku' => 'B', 'qty' => 2]],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('prices', 'v1', [
        ['row_key' => 'r1', 'data' => ['sku' => 'A', 'price' => 10]],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'prices', 'on' => 'sku'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'l1', 'data' => ['sku' => 'A', 'qty' => 5, 'price' => 10]],
    ]);
});

it('left join keeps unmatched rows with only the left data', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'l1', 'data' => ['sku' => 'A']],
        ['row_key' => 'l2', 'data' => ['sku' => 'B']],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('prices', 'v1', [
        ['row_key' => 'r1', 'data' => ['sku' => 'A', 'price' => 10]],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'prices', 'on' => 'sku', 'how' => 'left'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'l1', 'data' => ['sku' => 'A', 'price' => 10]],
        ['row_key' => 'l2', 'data' => ['sku' => 'B']],
    ]);
});

it('produces nothing (inner) for a right-hand node with no version yet', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'l1', 'data' => ['sku' => 'A']],
    ]);
    $ctx = executionContext(rowStore: new FakeRowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'prices', 'on' => 'sku'], $ctx);

    expect($result->rows())->toBe([]);
});

it('only queries mergeLookup for this batch\'s actual on-values, not every left row', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'l1', 'data' => ['sku' => 'A']],
        ['row_key' => 'l2', 'data' => ['sku' => 'A']], // duplicate on-value
        ['row_key' => 'l3', 'data' => ['sku' => null]], // no on-value at all
    ]);

    $rowStore = new class extends FakeRowStore
    {
        public array $lookupCalls = [];

        public function mergeLookup(string $nodeId, string $versionId, string $onField, array $onValues): array
        {
            $this->lookupCalls[] = $onValues;

            return parent::mergeLookup($nodeId, $versionId, $onField, $onValues);
        }
    };
    $rowStore->seedVersion('prices', 'v1', [
        ['row_key' => 'r1', 'data' => ['sku' => 'A', 'price' => 10]],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    (new MergeOperation)->apply($left, ['with' => 'prices', 'on' => 'sku'], $ctx);

    expect($rowStore->lookupCalls)->toBe([['A']]);
});

it('requires on', function () {
    expect((new MergeOperation)->requiredInputColumns(['on' => 'sku']))->toBe(['sku']);
});

// join keys of different types still match

it('matches a left int value against a right string value for the same on-field', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['albumId' => 5]],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('albums', 'v1', [
        ['row_key' => 'a1', 'data' => ['albumId' => '5', 'title' => 'Album Five']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'albums', 'on' => 'albumId'], $ctx);

    // Shared keys take the right side's value.
    expect($result->rows())->toBe([
        ['row_key' => 'p1', 'data' => ['albumId' => '5', 'title' => 'Album Five']],
    ]);
});

it('matches a left string value against a right int value, the reverse direction', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['albumId' => '5']],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('albums', 'v1', [
        ['row_key' => 'a1', 'data' => ['albumId' => 5, 'title' => 'Album Five']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'albums', 'on' => 'albumId'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'p1', 'data' => ['albumId' => 5, 'title' => 'Album Five']],
    ]);
});

// a join column missing on the right is drift, not an empty match

it('throws SchemaDriftException when the right-hand node has no rows carrying the on-field at all', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['albumId' => 5]],
    ]);

    $rowStore = new FakeRowStore;
    // The right side renamed albumId to album_id.
    $rowStore->seedVersion('albums', 'v1', [
        ['row_key' => 'a1', 'data' => ['album_id' => 5, 'title' => 'Album Five']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    expect(fn () => (new MergeOperation)->apply($left, ['with' => 'albums', 'on' => 'albumId'], $ctx))
        ->toThrow(SchemaDriftException::class);
});

// several matches get composite row_keys

it('gives each match a distinct composite row_key when the right side fans out', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['user_id' => 1, 'title' => 'post']],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('todos', 'v1', [
        ['row_key' => 't1', 'data' => ['user_id' => 1, 'todo' => 'a']],
        ['row_key' => 't2', 'data' => ['user_id' => 1, 'todo' => 'b']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'todos', 'on' => 'user_id'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'p1::t1', 'data' => ['user_id' => 1, 'title' => 'post', 'todo' => 'a']],
        ['row_key' => 'p1::t2', 'data' => ['user_id' => 1, 'title' => 'post', 'todo' => 'b']],
    ]);
});

it('keeps the bare left row_key when only a single match exists (no churn for the common case)', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['user_id' => 1]],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('todos', 'v1', [
        ['row_key' => 't1', 'data' => ['user_id' => 1, 'todo' => 'a']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'todos', 'on' => 'user_id'], $ctx);

    expect($result->rows())->toBe([
        ['row_key' => 'p1', 'data' => ['user_id' => 1, 'todo' => 'a']],
    ]);
});

it('does not throw when the right-hand node genuinely has the column, just no matching value', function () {
    $left = RowBatch::fromRows([
        ['row_key' => 'p1', 'data' => ['albumId' => 999]],
    ]);

    $rowStore = new FakeRowStore;
    $rowStore->seedVersion('albums', 'v1', [
        ['row_key' => 'a1', 'data' => ['albumId' => 5, 'title' => 'Album Five']],
    ]);
    $ctx = executionContext(rowStore: $rowStore);

    $result = (new MergeOperation)->apply($left, ['with' => 'albums', 'on' => 'albumId'], $ctx);

    expect($result->rows())->toBe([]);
});
