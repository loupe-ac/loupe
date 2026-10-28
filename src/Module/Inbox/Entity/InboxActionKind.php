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
    case ApprovePullRequest = 'approve-pull-request';
    case HoldCard = 'hold-card';

    /**
     * A worker can suggest the kind. Add entry is the owner's own step after a run-command suggestion, so no suggestion names it.
     * Open the pull request, approve it and hold the card answer a work-limit pause, which names the rule and its pull request.
     */
    public function isSuggestable(): bool
    {
        return !\in_array($this, [self::AddEntry, self::OpenPullRequest, self::ApprovePullRequest, self::HoldCard], true);
    }
}
