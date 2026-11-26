<?php

use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\ExpressionException;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\RunBudgetExceededException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Exceptions\TooManyRedirectsException;
use App\Support\PublicErrorMessage;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

function sourceHttpError(): RequestException
{
    return new RequestException(new Response(new PsrResponse(503, [], 'secret body from db.internal')));
}

it('shows the message of errors about the user\'s data, formulas and limits', function (Throwable $e) {
    expect(PublicErrorMessage::for(new PipelineException(2, 'map', $e)))
        ->toBe("Шаг 2 (map): {$e->getMessage()}");
})->with([
    'dsl' => [new DslException('Unsupported filter cmp: ~')],
    'expression' => [new ExpressionException('Variable "x" is not valid')],
    'unknown op' => [new UnknownOperationException('explode')],
    'schema drift' => [new SchemaDriftException(['price' => 'price'])],
    'limit' => [new LimitExceededException('Row limit exceeded')],
    'budget' => [new RunBudgetExceededException('Node run exceeded its budget')],
    'too large' => [new ResponseTooLargeException('Response exceeded the 10-byte limit.')],
]);

it('replaces fetch errors with fixed texts that carry no address', function (Closure $error, string $expected) {
    $e = $error();

    expect(PublicErrorMessage::for(new PipelineException(0, 'fetch', $e)))->toBe("Шаг 0 (fetch): {$expected}")
        ->and(PublicErrorMessage::forSource($e))->toBe($expected);
})->with([
    'ssrf' => [fn () => new SsrfBlockedException('host "db.internal" resolves to 10.0.0.5'), 'адрес источника запрещён'],
    'unresolved host' => [fn () => new HostUnresolvedException('host "db.internal" did not resolve'), 'не удалось найти адрес источника'],
    'connection' => [fn () => new ConnectionException('cURL error 28 for http://db.internal'), 'не удалось подключиться к источнику или он не ответил вовремя'],
    'redirects' => [fn () => new TooManyRedirectsException('fetching "http://db.internal"'), 'источник перенаправил слишком много раз'],
    'http status' => [fn () => sourceHttpError(), 'источник ответил HTTP 503'],
]);

it('says nothing specific about anything else', function (Closure $error) {
    expect(PublicErrorMessage::for($error()))->toEndWith(PublicErrorMessage::GENERIC)
        ->not->toContain('internal.')
        ->not->toContain('10.0.0.5');
})->with([
    'database' => [fn () => new PipelineException(1, 'merge', new QueryException('pgsql', 'select * from db.internal', [], new Exception('10.0.0.5')))],
    'llm' => [fn () => new PipelineException(1, 'llm', new RuntimeException('call failed: http://llm.internal?key=1'))],
    'llm http error' => [fn () => new PipelineException(1, 'llm', sourceHttpError())],
    'library InvalidArgumentException' => [fn () => new PipelineException(1, 'extract', new InvalidArgumentException('at db.internal'))],
    'outside a pipeline' => [fn () => new RuntimeException('db.internal')],
    'network error outside fetch' => [fn () => new ConnectionException('db.internal')],
]);
