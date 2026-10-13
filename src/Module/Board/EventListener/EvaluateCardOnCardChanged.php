<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Every change of a card face may change its markers, so each update queues a check of the card.
 * The update that the marker pass sends carries `markersOnly` and queues nothing, which ends the loop.
 * A new card has its own listener, and a deleted card has nothing left to check.
 */
#[AsEventListener]
final readonly class EvaluateCardOnCardChanged
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if ($event->markersOnly || CardChanged::UPDATED !== $event->change) {
            return;
        }
        $this->trigger->forCards([$event->cardId]);
    }
}
