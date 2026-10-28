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
    case RunCommand = 'run-command';
    case Dismiss = 'dismiss';
    case AddEntry = 'add-entry';
    case OpenPullRequest = 'open-pull-request';

    /**
     * A worker can suggest the kind. Add entry is the owner's own step after a run-command suggestion, so no suggestion names it.
     * Open the pull request links the pull request of a paused rule, which only a work-limit pause names.
     */
    public function isSuggestable(): bool
    {
        return self::AddEntry !== $this && self::OpenPullRequest !== $this;
    }
}
