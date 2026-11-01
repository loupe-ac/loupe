<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why the billed cost of a run is unknown. */
enum BilledCostReason: string
{
    case NoKey = 'no-key';
    case KeyRefused = 'key-refused';
    case NoAnswer = 'no-answer';
    case NoCostFound = 'no-cost-found';
}
