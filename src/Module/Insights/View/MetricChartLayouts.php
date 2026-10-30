<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;

/** Builds the layout that the Ds:Chart component draws for a chart type. */
final class MetricChartLayouts
{
    /**
     * Null when the series hold nothing to draw.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(MetricChartType $type, MetricShape $metric, MetricStatistic $statistic, MetricBucket $bucket, array $series, MetricValueFormatter $formatter): LineLayout|BarLayout|DonutLayout|NumberLayout|null
    {
        $adds = MetricChartRules::valuesAdd($metric, $statistic, \count($series));

        return match ($type) {
            MetricChartType::Line => LineLayout::build($metric, $statistic, $bucket, $series, $formatter, $adds),
            MetricChartType::Bars, MetricChartType::StackedBars, MetricChartType::PercentStacked => BarLayout::build($type, $metric, $statistic, $bucket, $series, $formatter, $adds),
            MetricChartType::Donut => DonutLayout::build($metric, $statistic, $series, $formatter),
            MetricChartType::Number => NumberLayout::build($metric, $statistic, $series, $formatter),
        };
    }
}
