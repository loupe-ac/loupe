<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** What the hover on a sign chip shows: the likely range and the card count of each period. A null range is unknown. */
final readonly class MetricChartSignDetail
{
    public function __construct(
        public ?MetricChartRange $current,
        public ?MetricChartRange $previous,
        public int $currentCards,
        public int $previousCards,
    ) {
    }
}
