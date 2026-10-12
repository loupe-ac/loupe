<?php

declare(strict_types=1);

namespace App\Module\AgentReview\EventListener;

use App\Module\AgentReview\Service\AgentReviewOverrideHistory;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A throw here rolls back the state Forge stored, so the next read writes again. */
#[AsEventListener]
final readonly class RecordAgentReviewOverrideOnPullRequestStateChanged
{
    public function __construct(
        private AgentReviewOverrideHistory $history,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $this->history->sync($event->pullRequest);
    }
}
