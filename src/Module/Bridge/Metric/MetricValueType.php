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

    /**
     * A ratio is a mean of ones and zeros, so a sum or a percentile of it means nothing.
     *
     * @return list<MetricStatistic>
     */
    public function statistics(): array
    {
        return self::Ratio === $this
            ? [MetricStatistic::Mean, MetricStatistic::Count]
            : MetricStatistic::cases();
    }
}
