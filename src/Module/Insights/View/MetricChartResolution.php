<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** The chart type to draw for a requested type, and why it differs from the request. */
final readonly class MetricChartResolution
{
    public function __construct(
        public MetricChartType $type,
        public MetricChartType $requested,
        /** Null when the page draws the requested type. */
        public ?MetricChartLock $reason,
    ) {
    }

    public function fellBack(): bool
    {
        return $this->type !== $this->requested;
    }
}
