<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** A likely range, with each end formatted for the reader. */
final readonly class MetricChartRange
{
    public function __construct(
        public string $low,
        public string $high,
    ) {
    }
}
