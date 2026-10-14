<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** The built-in actions of an inbox item. Loupe stores the backing value, so a rename needs a data migration. */
enum InboxActionKind: string
{
    case ResumeRun = 'resume-run';
    case RetryCard = 'retry-card';
    case Review = 'review';
    case Open = 'open';
    case SendAgent = 'send-agent';
    case StartSession = 'start-session';
    case Dismiss = 'dismiss';
}
