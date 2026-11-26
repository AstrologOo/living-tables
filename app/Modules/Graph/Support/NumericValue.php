<?php

namespace App\Modules\Graph\Support;

/**
 * What counts as a number in a number column: a number as JSON writes it, also inside a string, below 1e100 in
 * magnitude. Anything else would make the database fail on a cast or an aggregate.
 */
final class NumericValue
{
    private const PATTERN = '^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]{1,2})?$';

    private const MAX_LENGTH = 110;

    private const LIMIT = '1e100';

    public static function accepts(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return false;
        }

        $text = trim((string) $value, " \t\n\r");

        return strlen($text) <= self::MAX_LENGTH
            && preg_match('/'.self::PATTERN.'/D', $text) === 1
            && abs((float) $text) < (float) self::LIMIT;
    }

    /**
     * SQL reading a row's field as a number, NULL if it isn't one. CASE guarantees nothing is cast before it is
     * checked.
     *
     * @param  string  $driver  'pgsql' or 'sqlite'
     * @return array{0: string, 1: array<int, string>} the expression and its bindings
     */
    public static function sql(string $colId, string $driver): array
    {
        $limit = self::LIMIT;

        if ($driver === 'pgsql') {
            $text = "btrim(node_rows.data->>?, E' \\t\\n\\r')";
            $length = self::MAX_LENGTH;

            return [
                "CASE WHEN length({$text}) <= {$length} AND {$text} ~ ? THEN "
                    ."CASE WHEN abs({$text}::numeric) < {$limit} THEN {$text}::numeric END END",
                [$colId, $colId, self::PATTERN, $colId, $colId],
            ];
        }

        // sqlite has no regular expressions, but its JSON parser knows the same number syntax. json_type() of the
        // field itself excludes booleans, which ->> would read as 1 and 0.
        $text = 'trim(node_rows.data->>?, char(32, 9, 10, 13))';

        return [
            "CASE WHEN json_type(node_rows.data -> ?) IN ('integer', 'real', 'text') THEN "
                ."CASE WHEN json_valid({$text}) THEN CASE WHEN json_type({$text}) IN ('integer', 'real') "
                ."AND abs(CAST({$text} AS REAL)) < {$limit} THEN CAST({$text} AS REAL) END END END",
            [$colId, $colId, $colId, $colId, $colId],
        ];
    }
}
