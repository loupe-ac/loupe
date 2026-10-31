<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** Why an inbox item closed without a response of the owner. A response of the owner has no cause. */
enum InboxCloseCause: string
{
    case Withdrawn = 'withdrawn';
    case CardDeleted = 'card-deleted';
    case CardFinished = 'card-finished';
    case CauseEnded = 'cause-ended';
    case FactEnded = 'fact-ended';
    case CommandSucceeded = 'command-succeeded';
    case RuleWithdrew = 'rule-withdrew';
    case SwitchedOff = 'switched-off';
}
