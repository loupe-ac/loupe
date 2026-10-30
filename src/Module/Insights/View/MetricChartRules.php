<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/**
 * Which chart types a graph may take. Stacked bars, 100% stacked and Donut add
 * the lines together, so they need lines that add up to a whole.
 */
final class MetricChartRules
{
    /** @param int $lines the number of lines of the graph, by group or by bucket */
    public static function isAllowed(MetricChartType $type, MetricShape $metric, MetricStatistic $statistic, int $lines): bool
    {
        return null === self::lock($type, $metric, $statistic, $lines);
    }

    /** Null when the type is free. Otherwise the first thing the owner must change. */
    public static function lock(MetricChartType $type, MetricShape $metric, MetricStatistic $statistic, int $lines): ?MetricChartLock
    {
        if (!self::adds($type)) {
            return null;
        }
        if (MetricValueType::Ratio === $metric->valueType()) {
            return MetricChartLock::RateDoesNotAdd;
        }
        $mean = MetricStatistic::Mean === $statistic && ($metric->key->metric?->isBucketEach() ?? false);
        if (!$mean && MetricStatistic::Sum !== $statistic && MetricStatistic::Count !== $statistic) {
            return MetricChartLock::StatisticDoesNotAdd;
        }

        return $lines < 2 ? MetricChartLock::NeedsSeveralLines : null;
    }

    public static function fallback(): MetricChartType
    {
        return MetricChartType::Bars;
    }

    public static function resolve(MetricChartType $requested, MetricShape $metric, MetricStatistic $statistic, int $lines): MetricChartResolution
    {
        $lock = self::lock($requested, $metric, $statistic, $lines);

        return new MetricChartResolution(null === $lock ? $requested : self::fallback(), $requested, $lock);
    }

    private static function adds(MetricChartType $type): bool
    {
        return \in_array($type, [MetricChartType::StackedBars, MetricChartType::PercentStacked, MetricChartType::Donut], true);
    }
}
