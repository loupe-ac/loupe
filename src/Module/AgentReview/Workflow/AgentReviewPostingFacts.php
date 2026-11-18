<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\Workflow\Contract\TimedFacts;

/** What the rules that post the review check and the review summary comment read about one card. */
final readonly class AgentReviewPostingFacts implements TimedFacts
{
    /**
     * @param bool                $unposted    whether a review of the card has no check on the forge yet
     * @param list<string>        $uncommented the ids of the reviews that need a summary comment on the pull request
     * @param ?\DateTimeImmutable $changeAt    when the first of those reviews leaves the comment window, or null when none does
     */
    public function __construct(
        public bool $unposted,
        public array $uncommented,
        public ?\DateTimeImmutable $changeAt = null,
    ) {
    }

    #[\Override]
    public function changesAt(): ?\DateTimeImmutable
    {
        return $this->changeAt;
    }
}
