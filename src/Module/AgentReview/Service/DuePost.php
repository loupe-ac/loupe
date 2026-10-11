<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\Forge\Entity\ForgePullRequest;

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
    ) {
    }
}
