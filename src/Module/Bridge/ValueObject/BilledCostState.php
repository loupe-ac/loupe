<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Where the billed cost of a run stands. A billed or unknown cost is final. */
enum BilledCostState: string
{
    case Pending = 'pending';
    case Billed = 'billed';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return self::Pending !== $this;
    }
}
