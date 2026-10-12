<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Writes one history entry each time an override of a failed agent review starts or ends. The review keeps the
 * id of the approval it announced, so a repeated read of the same state writes nothing.
 */
final readonly class AgentReviewOverrideHistory
{
    public function __construct(
        private AgentReviewRepository $agentReviews,
        private AgentReviewOverride $override,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public function sync(ForgePullRequest $pullRequest): void
    {
        // A merged or closed pull request leaves the override as it stood, so a merge writes no end.
        if (PullRequestState::Open !== $pullRequest->state) {
            return;
        }

        $latest = null === $pullRequest->headSha ? null : ($this->agentReviews->findLatestOfHeads([$pullRequest])[(string) $pullRequest->id] ?? null);

        /** @var array<string, AgentReview> $reviews */
        $reviews = [];
        foreach ([...$this->agentReviews->findAnnouncedOfPullRequest($pullRequest), ...(null === $latest ? [] : [$latest])] as $review) {
            $reviews[(string) $review->id] = $review;
        }

        $changed = [];
        foreach ($reviews as $review) {
            $approver = $review === $latest ? $this->override->approverOf($review, $pullRequest) : null;
            $announced = $review->overrideAnnouncedFor;
            if (null !== $approver && $announced !== $pullRequest->latestApprovalId) {
                $this->cardEvents->record($review->card, CardEventKind::ReviewOverridden, Actor::System, null, [
                    'pullRequest' => $pullRequest->number,
                    'approver' => $approver,
                    'headSha' => $pullRequest->headSha,
                ]);
                $review->overrideAnnouncedFor = $pullRequest->latestApprovalId;
                $changed[(string) $review->card->id] = $review;
            } elseif (null === $approver && null !== $announced) {
                $this->cardEvents->record($review->card, CardEventKind::ReviewOverrideEnded, Actor::System, null, [
                    'pullRequest' => $pullRequest->number,
                    'headSha' => $review->headSha,
                ]);
                $review->overrideAnnouncedFor = null;
                $changed[(string) $review->card->id] = $review;
            }
        }
        if ([] === $changed) {
            return;
        }

        $this->em->flush();
        foreach ($changed as $review) {
            $this->events->dispatch(new CardChanged(
                $review->project->id ?? throw new \LogicException('A stored project has an id.'),
                $review->card->id ?? throw new \LogicException('A stored card has an id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
