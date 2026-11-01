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
    case Answer = 'answer';
    case Complete = 'complete';
    case SubmitReview = 'submit-review';

    /**
     * A worker can suggest the kind. Add entry is the owner's own step after a run-command suggestion, so no suggestion names it.
     * Open the pull request, approve it and hold the card answer a work-limit pause, which names the rule and its pull request.
     * Answer, complete and submit review are the owner's own words, so no agent suggests them.
     */
    public function isSuggestable(): bool
    {
        return !\in_array($this, [self::AddEntry, self::OpenPullRequest, self::ApprovePullRequest, self::HoldCard, self::Answer, self::Complete, self::SubmitReview], true);
    }

    /** Whether the kind closes the item with the response of the owner. */
    public function isResponse(): bool
    {
        return \in_array($this, [self::Dismiss, self::Answer, self::Complete, self::SubmitReview], true);
    }
}
