<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** Where the newest command run of an inbox item stands. */
enum RunCommandRunState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The bridge refused the request. The reason is a code. */
    case Refused = 'refused';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** The run waits or runs, so a second run would collide with it. */
    public function isLive(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }

    /** The owner can start the command again. */
    public function canRunAgain(): bool
    {
        return \in_array($this, [self::Failed, self::Refused, self::Expired, self::Cancelled], true);
    }
}
