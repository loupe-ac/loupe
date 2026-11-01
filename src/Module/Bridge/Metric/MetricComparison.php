<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Experiment\Interval;

/** One line of a graph in the current period against the same line in the previous period. */
final readonly class MetricComparison
{
    public function __construct(
        /** The statistic over the previous period, and the rows with a known value behind it. */
        public MetricPoint $previous,
        /** Null when one period has no known value. */
        public ?float $change,
        public ?MetricChangeKind $changeKind,
        public ?Interval $currentRange,
        public ?Interval $previousRange,
        public ?Interval $changeRange,
        /** Null for a sum, a count, a maximum and a reading, which have no likely range. */
        public ?MetricSign $sign,
        /** The distinct cards with a known value, on the current and on the previous period. */
        public int $currentCards,
        public int $previousCards,
    ) {
    }
}
