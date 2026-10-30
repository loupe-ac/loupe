<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One line of a hover card. A null text is an unknown value, which the template translates. */
final readonly class MetricChartHoverRow
{
    public function __construct(
        /** The template reads the label of the series by its position. */
        public MetricChartSeries $series,
        public ?string $value,
        /** The same line in the previous period. Null when the graph does not compare. */
        public ?string $previous,
        /** The part of the whole. Null when the values do not add up. */
        public ?string $share,
    ) {
    }
}
