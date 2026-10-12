<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;

/**
 * Answers whether an approval overrides a failed agent review. The approval must cover the current head, as the merge rule asks,
 * and the newest approval that stands must have come after Loupe stored the review.
 */
final readonly class AgentReviewOverride
{
    /** @return ?string the login of the approver, or null when the review stands */
    public function approverOf(AgentReview $review, ForgePullRequest $pullRequest): ?string
    {
        $approvedAt = $pullRequest->latestApprovedAt;
        $covers = PullRequestReview::Approved === $pullRequest->review
            && null !== $pullRequest->coveredSha
            && $pullRequest->coveredSha === $pullRequest->headSha;

        if (AgentReviewConclusion::Failure !== $review->conclusion
            || PullRequestState::Open !== $pullRequest->state
            || !$covers
            || null === $pullRequest->latestApprovalId
            || null === $approvedAt
            || $approvedAt <= $review->createdAt) {
            return null;
        }

        return $pullRequest->latestApproverLogin;
    }
}
