<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One series of a line chart. A bucket with no known value breaks the path. */
final readonly class MetricChartLine
{
    /** @param list<MetricChartDot> $dots */
    public function __construct(
        public MetricChartSeries $series,
        public string $path,
        public array $dots,
        /** The previous period on the dates of the current one. Empty when the graph does not compare. */
        public string $previousPath,
        /** The segment into the last point when its bucket is still open. Empty when the line ends in a closed bucket. */
        public string $openPath = '',
    ) {
    }
}
