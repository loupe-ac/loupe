<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use Psr\Clock\ClockInterface;

/**
 * Picks the reviews that need a summary comment: a review with findings whose own check landed in the last hour and that has no notice.
 * The window keeps the reviews of before a deploy out. A review carried to a later head has no check of its own on that head, so it counts once, under its own id.
 */
final readonly class UncommentedAgentReviews
{
    private const string RECENT = '-1 hour';

    public function __construct(
        private PullRequestNoticeRepository $pullRequestNotices,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<AgentReview> $latest the newest review of each open head
     *
     * @return list<AgentReview>
     */
    public function among(iterable $latest): array
    {
        $since = $this->clock->now()->modify(self::RECENT);
        $due = [];
        foreach ($latest as $review) {
            if (null === $review->postedAt || $review->postedAt < $since || [] === $review->findings) {
                continue;
            }
            $pullRequest = $review->pullRequest;
            $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
            if ($this->pullRequestNotices->hasKey($projectId, $pullRequest->forge, $pullRequest->repository, $pullRequest->number, AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('A stored review has an id.')))) {
                continue;
            }
            $due[] = $review;
        }

        return $due;
    }
}
