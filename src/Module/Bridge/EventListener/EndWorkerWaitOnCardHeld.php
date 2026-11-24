<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Command\EndWaitsOfCardCommand;
use App\Module\Bridge\Command\EndWaitsOfCardHandler;
use App\Module\Bridge\Event\CardHeld;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A held card is unmanaged, so a worker that waits on it stops waiting. */
#[AsEventListener]
final readonly class EndWorkerWaitOnCardHeld
{
    public function __construct(
        private EndWaitsOfCardHandler $endWaits,
    ) {
    }

    public function __invoke(CardHeld $event): void
    {
        ($this->endWaits)(new EndWaitsOfCardCommand($event->cardId, WorkerWaitEndReason::CardHeld));
    }
}
