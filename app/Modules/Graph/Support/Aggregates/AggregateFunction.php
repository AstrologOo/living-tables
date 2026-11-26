<?php

namespace App\Modules\Graph\Support\Aggregates;

/**
 * The closed set of aggregate functions.
 */
final class AggregateFunction
{
    public const COUNT = 'count';

    public const COUNT_DISTINCT = 'count_distinct';

    public const SUM = 'sum';

    public const AVG = 'avg';

    public const MIN = 'min';

    public const MAX = 'max';

    public const MEDIAN = 'median';

    public const STDDEV = 'stddev';

    public const VARIANCE = 'variance';

    public const ALL = [
        self::COUNT,
        self::COUNT_DISTINCT,
        self::SUM,
        self::AVG,
        self::MIN,
        self::MAX,
        self::MEDIAN,
        self::STDDEV,
        self::VARIANCE,
    ];

    /**
     * Functions whose input must be cast to NUMERIC for numeric columns.
     */
    public const NEEDS_NUMERIC_CAST = [
        self::SUM,
        self::AVG,
        self::MIN,
        self::MAX,
        self::MEDIAN,
        self::STDDEV,
        self::VARIANCE,
    ];

    private function __construct() {}

    /** Functions that only make sense over numbers, and whose result is one. */
    public const ARITHMETIC = [
        self::SUM,
        self::AVG,
        self::MEDIAN,
        self::STDDEV,
        self::VARIANCE,
    ];
}
