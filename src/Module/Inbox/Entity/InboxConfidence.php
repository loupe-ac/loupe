<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** How sure the agent is of the option it recommends. */
enum InboxConfidence: string
{
    case High = 'high';
    case Moderate = 'moderate';
    case Low = 'low';

    /** How many of the three stars the badge fills. */
    public function stars(): int
    {
        return match ($this) {
            self::High => 3,
            self::Moderate => 2,
            self::Low => 1,
        };
    }
}
