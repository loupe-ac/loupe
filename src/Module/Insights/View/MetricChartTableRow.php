<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricSign;

/** One series of the table under a chart, or one figure of the Number chart. A null text is an unknown value. */
final readonly class MetricChartTableRow
{
    public function __construct(
        public MetricChartSeries $series,
        /** The rows with a known value behind the figure. */
        public int $rows,
        public ?string $value,
        /** The part of the whole. Null when the values do not add up. */
        public ?string $share,
        /** The statistic over the previous period. Null when the graph does not compare. */
        public ?string $previous,
        /** Null when the graph does not compare, or when one period has no known value. */
        public ?MetricChartChange $change,
        /** Null for a sum, a count, or a graph that does not compare. */
        public ?MetricSign $sign,
        /** Null when the row has no sign. */
        public ?MetricChartSignDetail $signDetail,
    ) {
    }
}
