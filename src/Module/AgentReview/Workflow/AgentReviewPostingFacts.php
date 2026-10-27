<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

/** What the rules that post the review check and the review summary comment read about one card. */
final readonly class AgentReviewPostingFacts
{
    /**
     * @param bool         $unposted    whether a review of the card has no check on the forge yet
     * @param list<string> $uncommented the ids of the reviews that need a summary comment on the pull request
     */
    public function __construct(
        public bool $unposted,
        public array $uncommented,
    ) {
    }
}
