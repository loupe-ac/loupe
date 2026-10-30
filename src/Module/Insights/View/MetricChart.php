<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;

/**
 * The statistic of each bucket as bars side by side on a time axis, which is what the Metrics page
 * draws today. BarLayout holds the layout. Twig only prints these numbers.
 */
final readonly class MetricChart
{
    public const int WIDTH = MetricChartScale::WIDTH;
    public const int HEIGHT = MetricChartScale::HEIGHT;
    public const int PLOT_LEFT = MetricChartScale::PLOT_LEFT;
    public const int PLOT_RIGHT = MetricChartScale::PLOT_RIGHT;
    public const int PLOT_TOP = MetricChartScale::PLOT_TOP;
    public const int BASELINE = MetricChartScale::BASELINE;
    public const int PALETTE_SIZE = MetricChartScale::PALETTE_SIZE;

    /**
     * @param list<MetricChartBucket> $buckets oldest first
     * @param list<MetricChartSeries> $series
     * @param list<MetricChartTick>   $yTicks
     * @param list<MetricChartTick>   $xTicks
     */
    public function __construct(
        public MetricShape $metric,
        public MetricStatistic $statistic,
        public MetricBucket $bucket,
        public array $buckets,
        public array $series,
        public array $yTicks,
        /** The decimals of a dollar label on the value axis. */
        public int $moneyDecimals,
        public array $xTicks,
        /** True when the time axis spans more than a year, so a label needs its year. */
        public bool $longSpan,
    ) {
    }

    /**
     * Null when no series has a bucket.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(MetricShape $metric, MetricStatistic $statistic, MetricBucket $bucket, array $series): ?self
    {
        $layout = BarLayout::build(MetricChartType::Bars, $metric, $statistic, $bucket, $series, new MetricValueFormatter(\Locale::getDefault()), false);
        if (null === $layout) {
            return null;
        }

        return new self(
            metric: $layout->metric,
            statistic: $layout->statistic,
            bucket: $layout->bucket,
            buckets: $layout->buckets,
            series: $layout->series,
            yTicks: $layout->yTicks,
            moneyDecimals: $layout->moneyDecimals,
            xTicks: $layout->xTicks,
            longSpan: $layout->longSpan,
        );
    }

    /** A legend names the series when there is more than one. */
    public function hasLegend(): bool
    {
        return \count($this->series) > 1;
    }
}
