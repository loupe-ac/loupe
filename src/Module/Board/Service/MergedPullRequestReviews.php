<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;

/** Tells whether merged pull requests were reviewed. The AgentReview module implements it. */
interface MergedPullRequestReviews
{
    /**
     * A pull request counts as reviewed when the newest agent review of its last head passed, or when a person
     * overrode a failed one. The review of an older head counts when it covers the same change.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    public function hasUnreviewed(array $pullRequests): bool;
}
