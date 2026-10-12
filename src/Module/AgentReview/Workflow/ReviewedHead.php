<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;

/**
 * The head commit of one open pull request, and what the newest agent review of that commit concluded.
 * A fix round for the agent review has answered that review when it ended done after the review began.
 * An approval of the head that came after a failed review overrides it.
 */
final readonly class ReviewedHead
{
    public function __construct(
        public string $pullRequestId,
        public string $headSha,
        public ?AgentReviewConclusion $conclusion,
        public bool $answered = false,
        /** The login of the approver, when a pull request approval after the failed review overrides it. */
        public ?string $overriddenBy = null,
    ) {
    }
}
