<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricPoint;

/** The bar of one series in one bucket, in the coordinates of the chart's view box. */
final readonly class MetricChartBar
{
    public function __construct(
        public MetricChartSeries $series,
        public MetricPoint $point,
        public float $x,
        public float $width,
        /** The y of the top edge. The zero line for a zero or negative value, which hangs below it. */
        public float $top,
        /** Empty for a zero value, which has no height to draw. */
        public string $path,
        public float $height = 0.0,
        /** The part of the column that a stacked bar takes. Null for a bar beside others. */
        public ?float $share = null,
    ) {
    }
}
