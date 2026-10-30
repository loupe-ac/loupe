<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** Whether a change between two periods is real or noise. */
enum MetricSign: string
{
    case Clear = 'clear';
    case NotClearYet = 'not-clear-yet';
    /** Fewer than 5 cards with a known value on a period, or no known value on one. */
    case NotEnoughCards = 'not-enough-data';
}
