<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A flip finishes or reopens the column's cards, which changes their epics and the cards they block, as a move does. */
#[AsEventListener]
final readonly class EvaluateCardsOnBoardColumnTerminalChanged
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $this->trigger->forCards([...$event->cardIds, ...$this->cards->findRelatedForEvaluation($event->cardIds)]);
    }
}
