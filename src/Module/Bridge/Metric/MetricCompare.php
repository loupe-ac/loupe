<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricCompare: string
{
    case Off = 'off';
    case Previous = 'previous';
}
