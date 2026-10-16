<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card that waits for a bridge shows whether one could take its work, so it evaluates again when a bridge changes. */
#[AsEventListener]
final readonly class EvaluateCardsOnBridgeAvailabilityChanged
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BridgeAvailabilityChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }
        $this->trigger->forCards($this->workRequests->findCardIdsWithOpenRequestIn($event->projectIds));
    }
}
