<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Workflow\AccountUsageFacts;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\ProjectsReadingFacts;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A reading changes the cards that wait for a bridge, and every card of a project whose workflow tests account usage. */
#[AsEventListener]
final readonly class EvaluateCardsOnBridgeAccountReadingsStored
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private CardRepository $cards,
        private ProjectsReadingFacts $readers,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BridgeAccountReadingsStored $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }
        $cardIds = $this->workRequests->findCardIdsWithOpenRequestIn($event->projectIds);
        $readers = $this->readers->among($event->projectIds, AccountUsageFacts::class);
        if ([] !== $readers) {
            $cardIds = [...$cardIds, ...$this->cards->findIdsInOpenColumnsOfProjects($readers)];
        }
        $this->trigger->forCards($cardIds, self::class);
    }
}
