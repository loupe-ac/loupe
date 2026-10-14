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
}
