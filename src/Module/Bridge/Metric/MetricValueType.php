<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricValueType: string
{
    case Money = 'money';
    case Tokens = 'tokens';
    case Duration = 'duration';
    case Count = 'count';
    case Ratio = 'ratio';
    case Boolean = 'boolean';
    case Percent = 'percent';

    /**
     * A ratio is a mean of ones and zeros, so a sum or a percentile of it means nothing. A percent is a share of a
     * limit, so a sum of it means nothing either, and its peak is the figure that matters. No other type offers the max.
     *
     * @return list<MetricStatistic>
     */
    public function statistics(): array
    {
        return match ($this) {
            self::Ratio => [MetricStatistic::Mean, MetricStatistic::Count],
            self::Percent => [MetricStatistic::Max, MetricStatistic::Mean, MetricStatistic::Median, MetricStatistic::P90, MetricStatistic::Count],
            default => [MetricStatistic::Median, MetricStatistic::Mean, MetricStatistic::Sum, MetricStatistic::P90, MetricStatistic::Count],
        };
    }
}
