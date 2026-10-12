<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A column delete moves its cards in bulk and dispatches no CardMoved for them.
 * Only a move that finishes or reopens the cards changes their epics and the cards they block.
 */
#[AsEventListener]
final readonly class EvaluateCardsOnBoardColumnDeleted
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $related = $event->terminal !== $event->targetTerminal ? $this->cards->findRelatedForEvaluation($event->movedCardIds) : [];
        $this->trigger->forCards([...$event->movedCardIds, ...$related]);
    }
}
