<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricPoint;

/** The point of one series in one bucket on a line chart. */
final readonly class MetricChartDot
{
    public function __construct(
        public MetricPoint $point,
        public float $x,
        public float $y,
    ) {
    }
}
