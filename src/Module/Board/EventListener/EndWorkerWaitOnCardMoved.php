<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Bridge\Command\EndWaitsOfCardCommand;
use App\Module\Bridge\Command\EndWaitsOfCardHandler;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The worker waited for the card in the column it left. A move into a terminal column finishes the card.
 * A move inside the same column, such as a new rank, leaves the wait open.
 */
#[AsEventListener]
final readonly class EndWorkerWaitOnCardMoved
{
    public function __construct(
        private EndWaitsOfCardHandler $endWaits,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        if ($event->move->fromColumn->id?->equals($event->card->column->id) ?? false) {
            return;
        }

        ($this->endWaits)(new EndWaitsOfCardCommand(
            $event->card->id ?? throw new \LogicException('A moved card has an id.'),
            $event->card->column->terminal ? WorkerWaitEndReason::CardFinished : WorkerWaitEndReason::CardMoved,
        ));
    }
}
