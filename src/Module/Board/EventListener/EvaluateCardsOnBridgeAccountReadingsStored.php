<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A rule can test the usage of a bridge account, so a new reading evaluates the cards that rules act on. */
#[AsEventListener]
final readonly class EvaluateCardsOnBridgeAccountReadingsStored
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BridgeAccountReadingsStored $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }
        $this->trigger->forCards($this->cards->findIdsInOpenColumnsOfProjects($event->projectIds));
    }
}
