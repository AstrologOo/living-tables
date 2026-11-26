<?php

namespace App\Modules\Execution\Operations;

use App\Modules\Execution\Contracts\Operation;
use App\Modules\Execution\Exceptions\ExpressionException;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\RowBatch;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;

/**
 * Selects, renames or computes columns with symfony/expression-language; custom functions must also be listed in
 * DslValidator. String literals lose single backslashes, so regex patterns need them doubled.
 *
 * config: {fields: {new_col_id: col_id | {expr: string}}}
 */
class MapOperation implements Operation
{
    private ExpressionLanguage $expressionLanguage;

    public function __construct(?ExpressionLanguage $expressionLanguage = null)
    {
        $this->expressionLanguage = $expressionLanguage ?? new ExpressionLanguage;
        $this->registerCustomFunctions();
    }

    private function registerCustomFunctions(): void
    {
        $this->expressionLanguage->register(
            'extract_number',
            static fn (string $text) => sprintf('\%s::extractNumber(%s)', self::class, $text),
            static fn (array $values, mixed $text) => self::extractNumber($text),
        );

        $this->expressionLanguage->register(
            'is_fibonacci',
            static fn (string $n) => sprintf('\%s::isFibonacci(%s)', self::class, $n),
            static fn (array $values, mixed $n) => self::isFibonacci($n),
        );

        $this->expressionLanguage->register(
            'regex_extract',
            static fn ($text, $pattern, $group = 0) => sprintf('\%s::regexExtract(%s, %s, %s)', self::class, $text, $pattern, $group),
            static fn (array $values, mixed $text, string $pattern, int $group = 0) => self::regexExtract($text, $pattern, $group),
        );
    }

    /**
     * The first number found in a text, or null if there is none.
     */
    public static function extractNumber(mixed $text): int|float|null
    {
        if (is_int($text) || is_float($text)) {
            return $text;
        }

        if (! is_string($text) || ! preg_match('/-?\d+(\.\d+)?/', $text, $matches)) {
            return null;
        }

        return str_contains($matches[0], '.') ? (float) $matches[0] : (int) $matches[0];
    }

    /**
     * Whether $n is a Fibonacci number: 5n²+4 or 5n²-4 is a perfect square.
     */
    public static function isFibonacci(mixed $n): bool
    {
        if (! is_int($n) && ! (is_float($n) && $n == (int) $n)) {
            return false;
        }

        $n = (int) $n;

        return $n >= 0 && (self::isPerfectSquare(5 * $n * $n + 4) || self::isPerfectSquare(5 * $n * $n - 4));
    }

    private static function isPerfectSquare(int $x): bool
    {
        if ($x < 0) {
            return false;
        }

        $sqrt = (int) round(sqrt($x));

        return $sqrt * $sqrt === $x;
    }

    /**
     * The pattern's match, or its capture group $group; null if nothing matched.
     */
    public static function regexExtract(mixed $text, string $pattern, int $group = 0): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $matches = self::runPregMatch($pattern, $text);

        return $matches[$group] ?? null;
    }

    /**
     * Turns preg_match()'s warning on a bad pattern into an exception. An error handler is used because test runners
     * still record warnings hidden with @.
     *
     * @return array<int, string>
     */
    private static function runPregMatch(string $pattern, string $text): array
    {
        set_error_handler(static fn () => throw new \InvalidArgumentException(
            sprintf('Invalid regex pattern "%s": %s', $pattern, preg_last_error_msg())
        ));

        try {
            preg_match($pattern, $text, $matches);
        } finally {
            restore_error_handler();
        }

        return $matches;
    }

    public function apply(RowBatch $input, array $config, ExecutionContext $ctx): RowBatch
    {
        $fields = $config['fields'];

        $rows = array_map(
            fn (array $row) => ['row_key' => $row['row_key'], 'data' => $this->mapRow($row['data'], $fields)],
            $input->rows()
        );

        return $input->withRows($rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function mapRow(array $data, array $fields): array
    {
        $mapped = [];

        foreach ($fields as $newColId => $spec) {
            $mapped[$newColId] = is_array($spec)
                ? $this->evaluate($spec['expr'], $data)
                : ($data[$spec] ?? null);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ExpressionException
     */
    private function evaluate(string $expression, array $data): mixed
    {
        try {
            return $this->expressionLanguage->evaluate($expression, $data);
        } catch (Throwable $e) {
            throw new ExpressionException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Only plain references; expressions report unknown variables themselves.
     */
    public function requiredInputColumns(array $config): array
    {
        return array_values(array_filter($config['fields'], fn ($spec) => ! is_array($spec)));
    }
}
