<?php

use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Execution\OperationFactory;

it('throws for an unknown operation name', function () {
    expect(fn () => (new OperationFactory)->make('not_a_real_op'))
        ->toThrow(UnknownOperationException::class, 'Unknown DSL operation: "not_a_real_op".');
});
