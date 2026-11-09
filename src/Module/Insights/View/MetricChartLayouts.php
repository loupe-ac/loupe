<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** Builds the layout that the Ds:Chart component draws for a chart type. */
final class MetricChartLayouts
{
    /**
     * Null when the current window holds no rows, even when the previous period holds some.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(MetricChartType $type, MetricShape|MetricValueType $metric, ?MetricStatistic $statistic, MetricBucket $bucket, array $series, MetricValueFormatter $formatter, ?ChartBox $box = null): LineLayout|BarLayout|DonutLayout|NumberLayout|null
    {
        if ([] === array_filter($series, static fn (MetricSeries $line): bool => [] !== $line->rows)) {
            return null;
        }

        $adds = MetricChartRules::valuesAdd($metric, $statistic, \count($series));

        return match ($type) {
            MetricChartType::Line => LineLayout::build($metric, $statistic, $bucket, $series, $formatter, $adds, $box ?? new ChartBox()),
            MetricChartType::Bars, MetricChartType::StackedBars, MetricChartType::PercentStacked => BarLayout::build($type, $metric, $statistic, $bucket, $series, $formatter, $adds, $box ?? new ChartBox()),
            MetricChartType::Donut => DonutLayout::build($metric, $statistic, $series, $formatter, $box),
            MetricChartType::Number => NumberLayout::build($metric, $statistic, $series, $formatter, $box ?? new ChartBox()),
        };
    }
}
