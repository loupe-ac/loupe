<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new card starts the rules of the column it lands in, the same as a move into that column. */
#[AsEventListener]
final readonly class EvaluateCardOnCardCreated
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::CREATED !== $event->change) {
            return;
        }
        $this->trigger->forCards([$event->cardId]);
    }
}
