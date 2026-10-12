<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\AgentReviewComment;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\PullRequestNoticeQueue;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Stores one pull request notice for each agent review of the card that needs a summary comment. */
#[AsAlias(AgentReviewComment::class)]
final readonly class AgentReviewCommentQueue implements AgentReviewComment
{
    public function __construct(
        private CardPullRequests $cardPullRequests,
        private AgentReviewRepository $agentReviews,
        private UncommentedAgentReviews $uncommented,
        private PullRequestNoticeQueue $notices,
        private EntityManagerInterface $em,
    ) {
    }

    /** One savepoint holds the reads too, so a failed statement leaves the transaction of the caller usable. */
    #[\Override]
    public function queue(Card $card): bool
    {
        return $this->em->getConnection()->transactional(function () use ($card): bool {
            $open = array_values(array_filter(
                $this->cardPullRequests->forCard($card),
                static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state,
            ));

            $queued = false;
            foreach ($this->uncommented->among($this->agentReviews->findLatestOfHeads($open)) as $review) {
                $queued = $this->notices->queue($review->pullRequest, AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('A stored review has an id.'))) || $queued;
            }

            return $queued;
        });
    }
}
