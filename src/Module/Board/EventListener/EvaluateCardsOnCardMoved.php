<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A move changes the slot of the card, the children of its epic, and the blockers of the cards it blocks. */
#[AsEventListener]
final readonly class EvaluateCardsOnCardMoved
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $cardId = (string) ($event->card->id ?? throw new \LogicException('A moved card has an id.'));
        $this->trigger->forCards([$cardId, ...$this->cards->findRelatedForEvaluation([$cardId])]);
    }
}
