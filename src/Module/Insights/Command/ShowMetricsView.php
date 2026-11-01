<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Command\MetricQueryView;
use App\Module\Insights\View\BarLayout;
use App\Module\Insights\View\DonutLayout;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricChartLock;
use App\Module\Insights\View\MetricChartResolution;
use App\Module\Insights\View\NumberLayout;
use App\Module\Project\Entity\Project;

final readonly class ShowMetricsView
{
    /**
     * @param array<string, ?MetricChartLock> $locks  why each chart type is locked, by type value, null when it is free
     * @param list<string>                    $labels the name of each series, by position
     */
    public function __construct(
        public Project $project,
        public MetricQueryView $metrics,
        public string $title,
        public MetricChartResolution $chart,
        public array $locks,
        public LineLayout|BarLayout|DonutLayout|NumberLayout|null $layout,
        public array $labels,
    ) {
    }
}
