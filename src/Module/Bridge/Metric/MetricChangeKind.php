<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricChangeKind: string
{
    /** The difference as a fraction of the previous value. */
    case Relative = 'relative';
    /** The difference of two rates, in percentage points as a fraction. */
    case Points = 'points';
    /** The difference in the unit of the metric, when the previous value is zero. */
    case Absolute = 'absolute';
}
