<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The kind of a card marker. The case order is the precedence, so the first matching kind is the one a card shows. */
enum MarkerKind: string
{
    case Stuck = 'stuck';
    case NeedsYou = 'needs-you';
    case Working = 'working';
    case Waiting = 'waiting';
    case Chip = 'chip';
}
