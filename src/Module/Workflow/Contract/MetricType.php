<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum MetricType: string
{
    case Count = 'count';
    case Rate = 'rate';
    case Duration = 'duration';
}
