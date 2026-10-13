<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\Workflow\Contract\Facts;

/** What the agent review rules read about one card. */
final readonly class AgentReviewFacts
{
    /**
     * @param list<ReviewedHead> $heads       the open pull requests of the card that have a head commit
     * @param bool               $epic        whether the card type may have children, so its pull request needs no review
     * @param bool               $unposted    whether a review of the card has no check on the forge yet
     * @param list<string>       $uncommented the ids of the reviews that need a summary comment on the pull request
     */
    public function __construct(
        public array $heads,
        public bool $epic,
        public bool $unposted,
        public array $uncommented,
    ) {
    }

    /** The head of the pull request a rule acts on, or null when the rule acts on none or it has no head. */
    public function boundHead(Facts $facts): ?ReviewedHead
    {
        $id = $facts->pullRequest?->id;

        return null === $id ? null : array_find($this->heads, static fn (ReviewedHead $head): bool => $head->pullRequestId === (string) $id);
    }
}
