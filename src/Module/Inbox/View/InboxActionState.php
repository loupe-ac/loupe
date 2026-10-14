<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** Where an action of an inbox item stands. The owner gets the reply card back once the state is Expired, Refused or Cancelled. */
enum InboxActionState: string
{
    case Queued = 'queued';
    case Expired = 'expired';
    case Refused = 'refused';
    case Cancelled = 'cancelled';
    case Resumed = 'resumed';
    case Preparing = 'preparing';
    case Retried = 'retried';
    case Running = 'running';

    /** The action still answers the wait, so the thread shows no reply card beside it. */
    public function inProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Resumed, self::Preparing, self::Retried, self::Running => true,
            self::Expired, self::Refused, self::Cancelled => false,
        };
    }
}
