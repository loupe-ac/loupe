<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

final readonly class MetricSeries
{
    /**
     * @param list<MetricPoint> $points         oldest bucket first, only the buckets with rows
     * @param list<MetricRow>   $rows           newest first
     * @param list<MetricPoint> $previousPoints the previous period moved forward onto the dates of the current one, oldest bucket first
     */
    public function __construct(
        public ?string $group,
        public array $points,
        public MetricPoint $total,
        public array $rows,
        public ?MetricComparison $comparison = null,
        public array $previousPoints = [],
    ) {
    }
}
