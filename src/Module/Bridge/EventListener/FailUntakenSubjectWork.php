<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\UntakenSubjectWork;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Checks for a taker when a request opens, and again when what a bridge can take changes.
 * The priority is negative, because every other listener must hear the open event first.
 * The expiry dispatches its own event inside that dispatch.
 */
#[AsEventListener(event: WorkRequestChanged::class, method: 'onWorkRequestChanged', priority: -10)]
#[AsEventListener(event: BridgeAvailabilityChanged::class, method: 'onBridgeAvailabilityChanged', priority: -10)]
final readonly class FailUntakenSubjectWork
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private UntakenSubjectWork $untaken,
    ) {
    }

    public function onWorkRequestChanged(WorkRequestChanged $event): void
    {
        if (WorkRequestState::Open !== $event->state || null !== $event->cardId()) {
            return;
        }
        $request = $this->workRequests->find($event->workRequestId);
        if (true === $request?->failWhenNoTaker) {
            $this->untaken->expireWhenUntaken($request);
        }
    }

    public function onBridgeAvailabilityChanged(BridgeAvailabilityChanged $event): void
    {
        foreach ($this->workRequests->findOpenOfOtherSubjectsThatFailWhenNoTaker($event->projectIds) as $request) {
            $this->untaken->expireWhenUntaken($request);
        }
    }
}
