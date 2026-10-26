<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Service\MergedPullRequestReviews;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MergedPullRequestReviews::class)]
final readonly class MergedPullRequestReviewReader implements MergedPullRequestReviews
{
    public function __construct(
        private AgentReviewRepository $agentReviews,
    ) {
    }

    #[\Override]
    public function hasUnreviewed(array $pullRequests): bool
    {
        $latest = $this->agentReviews->findLatestOfHeads($pullRequests);
        foreach ($pullRequests as $pullRequest) {
            $review = $latest[(string) $pullRequest->id] ?? null;
            if (null === $review || (AgentReviewConclusion::Success !== $review->conclusion && null === $review->overrideAnnouncedFor)) {
                return true;
            }
        }

        return false;
    }
}
