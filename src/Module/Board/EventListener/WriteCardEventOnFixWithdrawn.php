<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A fix request that the workflow cancelled because the head of its pull request moved leaves one history row with both heads. */
#[AsEventListener]
final readonly class WriteCardEventOnFixWithdrawn
{
    private const string KIND = 'fix';

    /** The `cancelReason` of a fix rule whose `cancelWhen` reads a moved head. */
    public const string REASON = 'head-moved';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private CardRepository $cards,
        private CardPullRequests $cardPullRequests,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        if (null === $cardId || WorkRequestState::Cancelled !== $event->state) {
            return;
        }
        $request = $this->workRequests->find($event->workRequestId);
        $oldHead = $request?->context->headSha;
        if (null === $request || self::KIND !== $request->kind || self::REASON !== $request->reason || null === $oldHead || null === $request->context->pullRequestNumber) {
            return;
        }
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return;
        }
        foreach ($this->cardPullRequests->forCard($card) as $pullRequest) {
            $newHead = $pullRequest->headSha;
            if (PullRequestState::Open !== $pullRequest->state || $pullRequest->number !== $request->context->pullRequestNumber || null === $newHead || 0 === strcasecmp($newHead, $oldHead)) {
                continue;
            }
            $this->cardEvents->record($card, CardEventKind::FixWithdrawn, Actor::System, null, [
                'pullRequest' => $pullRequest->number,
                'oldHeadSha' => $oldHead,
                'newHeadSha' => $newHead,
            ], $request->settledAt);
            $this->em->flush();

            return;
        }
    }
}
