<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricWindow;

/**
 * A graph as the Dashboard drew it, ready to keep. It holds the finished layout and no rows, no project
 * and no entity, so it serializes small. A null layout means the current window holds no rows.
 */
final readonly class DashboardGraphResult
{
    /**
     * @param list<string>          $labels     the name of each series, by position
     * @param list<?MetricGroupKey> $seriesKeys the key of each series in the order of the labels, null for a series that no account or bridge makes
     */
    public function __construct(
        /** The clock reading both windows count back from. */
        public \DateTimeImmutable $computedAt,
        public string $title,
        public MetricChartResolution $chart,
        public LineLayout|BarLayout|DonutLayout|NumberLayout|null $layout,
        public array $labels,
        public array $seriesKeys,
        public MetricWindow $current,
        /** Null when the graph does not compare. */
        public ?MetricWindow $previous,
    ) {
    }
}
