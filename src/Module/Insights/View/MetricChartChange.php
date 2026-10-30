<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricChangeKind;

/** A change figure: an arrow, and a size with no sign. The template adds "pts" after the size of a change of rates. */
final readonly class MetricChartChange
{
    public function __construct(
        public MetricChartDirection $direction,
        /** The size, formatted for the reader. */
        public string $amount,
        public MetricChangeKind $kind,
    ) {
    }
}
