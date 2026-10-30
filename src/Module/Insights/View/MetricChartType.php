<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** How the Metrics page draws a graph. The values reach URLs and saved graphs, so they stay stable. */
enum MetricChartType: string
{
    case Line = 'line';
    case Bars = 'bars';
    case StackedBars = 'stacked-bars';
    case PercentStacked = 'percent-stacked';
    case Donut = 'donut';
    case Number = 'number';

    public static function default(): self
    {
        return self::Line;
    }
}
