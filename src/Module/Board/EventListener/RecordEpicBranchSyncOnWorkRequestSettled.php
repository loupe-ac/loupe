<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ends a fallback merge run of the epic branch on the epic. It runs before the card evaluates again,
 * so a child never reads its epic branch as not synced for a run that just merged.
 */
#[AsEventListener(priority: 10)]
final readonly class RecordEpicBranchSyncOnWorkRequestSettled
{
    public function __construct(
        private CardRepository $cards,
        private WorkRequestRepository $workRequests,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        if (null === $cardId || $event->state->isLive()) {
            return;
        }
        $epic = $this->cards->find($cardId)?->parent;
        if (null === $epic || !\array_key_exists($event->workRequestId->toRfc4122(), $epic->epicBranchPendingSyncs)) {
            return;
        }

        // A run that ends with another reason can resume, so the epic awaits it still.
        $merged = WorkRequestState::Done === $event->state && 'done' === $this->workRequests->find($event->workRequestId)?->reason;
        if (WorkRequestState::Done === $event->state && !$merged) {
            return;
        }
        $epic->settleEpicBranchSync($event->workRequestId, $merged);
        $this->em->flush();
        $this->logger->info('board.epic_branch_sync_settled', [
            'cardId' => (string) $cardId,
            'epicId' => (string) $epic->id,
            'workRequestId' => (string) $event->workRequestId,
            'merged' => $merged,
        ]);
        if ($merged && $this->evaluations->isOn()) {
            $this->evaluations->forCards($this->cards->findChildIds($epic->id ?? throw new \LogicException('A stored card has an id.')));
        }
    }
}
