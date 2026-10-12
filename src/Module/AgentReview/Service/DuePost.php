<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;

/** A check that Loupe still has to post: a review on its own head, or a review carried to a later head. */
final readonly class DuePost
{
    public function __construct(
        public AgentReview $review,
        public ForgePullRequest $pullRequest,
        public string $headSha,
        public bool $carried,
        /** The row of an earlier attempt of a carried post, or null when none was made yet. */
        public ?AgentReviewPost $post = null,
        /** The login of the approver that overrides the failed review, or null when none does. */
        public ?string $approver = null,
    ) {
    }

    /** What the check of this review must show: neutral for an overridden failure. */
    public function conclusion(): PullRequestCheckConclusion
    {
        return match (true) {
            AgentReviewConclusion::Failure !== $this->review->conclusion => PullRequestCheckConclusion::Success,
            null !== $this->approver => PullRequestCheckConclusion::Neutral,
            default => PullRequestCheckConclusion::Failure,
        };
    }

    /** What the check of this review shows now. A check posted before overrides existed shows the review conclusion. */
    public function shown(): PullRequestCheckConclusion
    {
        $target = $this->carried ? $this->post : $this->review;

        return $target->checkConclusion
            ?? (AgentReviewConclusion::Failure === $this->review->conclusion ? PullRequestCheckConclusion::Failure : PullRequestCheckConclusion::Success);
    }
}
