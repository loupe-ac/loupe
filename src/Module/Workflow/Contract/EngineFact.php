<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A group of facts that the engine itself builds, as opposed to the facts of a provider. */
enum EngineFact: string
{
    case Slot = 'slot';
    case ParentSlot = 'parent-slot';
    case PullRequest = 'pull-request';
    case PullRequestSubject = 'pull-request-subject';
    case PullRequestHead = 'pull-request-head';
    case PullRequestMergeGate = 'pull-request-merge-gate';

    /** Whether a rule that reads this group acts on one open pull request of the card. */
    public function bindsPullRequest(): bool
    {
        return self::PullRequest === $this || self::PullRequestSubject === $this;
    }
}
