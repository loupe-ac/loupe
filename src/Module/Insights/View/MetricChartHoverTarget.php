<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/**
 * An invisible area of the plot with a hover card. A time bucket is a rectangle. A donut slice is a path,
 * and its rectangle is empty.
 */
final readonly class MetricChartHoverTarget
{
    /** @param list<MetricChartHoverRow> $rows */
    public function __construct(
        public string $id,
        /** The first day of the bucket. Null for a slice. */
        public ?\DateTimeImmutable $start,
        /** The last day of the bucket. Null for a slice. */
        public ?\DateTimeImmutable $end,
        /** The slice that the target stands for. Null for a bucket. */
        public ?MetricChartSeries $series,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        /** Where the card points to. */
        public float $anchorX,
        public float $anchorY,
        /** The outline of a slice. Empty for a bucket. */
        public string $path,
        public array $rows,
        /** The sum of the rows, when the values add up. */
        public ?string $total,
    ) {
    }
}
