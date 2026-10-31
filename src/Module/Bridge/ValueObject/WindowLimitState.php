<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What the latest reading of a usage window says about new work. */
enum WindowLimitState: string
{
    case AtLimit = 'at-limit';
    case AtStop = 'at-stop';
    case Open = 'open';
    case Unknown = 'unknown';

    public function blocks(): bool
    {
        return self::AtLimit === $this || self::AtStop === $this;
    }
}
