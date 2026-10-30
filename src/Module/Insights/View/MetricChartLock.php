<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** Why a chart type that adds its lines together is locked. The page words each case. */
enum MetricChartLock: string
{
    /** The graph has one line, so there is nothing to add. */
    case NeedsSeveralLines = 'needs-several-lines';
    /** A median, a percentile or a plain mean of lines does not give the whole. */
    case StatisticDoesNotAdd = 'statistic-does-not-add';
    /** A rate is a share, so the rates of lines never add up. */
    case RateDoesNotAdd = 'rate-does-not-add';
}
