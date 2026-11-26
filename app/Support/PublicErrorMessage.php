<?php

namespace App\Support;

use App\Modules\Execution\Exceptions\DslException;
use App\Modules\Execution\Exceptions\LimitExceededException;
use App\Modules\Execution\Exceptions\PipelineException;
use App\Modules\Execution\Exceptions\RunBudgetExceededException;
use App\Modules\Execution\Exceptions\SchemaDriftException;
use App\Modules\Execution\Exceptions\UnknownOperationException;
use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Exceptions\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The error text a client may see. Only listed exception types say anything specific, as raw messages can reveal
 * hosts, API keys and SQL.
 */
final class PublicErrorMessage
{
    public const GENERIC = 'внутренняя ошибка, подробности записаны в журнал сервера';

    public const ADDRESS_FORBIDDEN = 'адрес источника запрещён';

    public const ADDRESS_NOT_FOUND = 'не удалось найти адрес источника';

    public const SOURCE_UNREACHABLE = 'не удалось подключиться к источнику или он не ответил вовремя';

    public const TOO_MANY_REDIRECTS = 'источник перенаправил слишком много раз';

    public const GENERATION_UNAVAILABLE = 'сервис генерации недоступен';

    /** A node given up on by graph:recover-stuck-nodes: there is no exception to describe. */
    public const RUN_INTERRUPTED = 'расчёт несколько раз прервался и не был завершён';

    /** Types whose own message is about the user's data, formulas or limits. */
    private const SHOWN_AS_IS = [
        DslException::class,
        UnknownOperationException::class,
        SchemaDriftException::class,
        LimitExceededException::class,
        RunBudgetExceededException::class,
        ResponseTooLargeException::class,
    ];

    /** A failed pipeline step is reported with its number and operation. */
    public static function for(Throwable $e): string
    {
        if ($e instanceof PipelineException) {
            // Network errors only describe "the source" when they come from
            // fetch: an LLM call fails with the very same HTTP exceptions.
            $text = self::describe($e->getPrevious(), fromSource: $e->op === 'fetch');

            return "Шаг {$e->step} ({$e->op}): {$text}";
        }

        return self::describe($e, fromSource: false);
    }

    /** For a request made to a user's source outside any pipeline (the probe). */
    public static function forSource(Throwable $e): string
    {
        return self::describe($e, fromSource: true);
    }

    private static function describe(Throwable $e, bool $fromSource): string
    {
        foreach (self::SHOWN_AS_IS as $type) {
            if ($e instanceof $type) {
                return $e->getMessage();
            }
        }

        if (! $fromSource) {
            return self::GENERIC;
        }

        return match (true) {
            // A subclass of SsrfBlockedException, so matched first.
            $e instanceof HostUnresolvedException => self::ADDRESS_NOT_FOUND,
            $e instanceof SsrfBlockedException => self::ADDRESS_FORBIDDEN,
            $e instanceof ConnectionException => self::SOURCE_UNREACHABLE,
            $e instanceof TooManyRedirectsException => self::TOO_MANY_REDIRECTS,
            // Status only: the message also quotes the response body.
            $e instanceof RequestException => "источник ответил HTTP {$e->response->status()}",
            default => self::GENERIC,
        };
    }
}
