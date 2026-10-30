<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One slice of a donut. The angles run clockwise in degrees from the top. */
final readonly class MetricChartSlice
{
    public function __construct(
        public MetricChartSeries $series,
        public int|float $value,
        /** A fraction of the whole, above 0 and up to 1. */
        public float $share,
        public float $startAngle,
        public float $endAngle,
        public string $path,
    ) {
    }
}
