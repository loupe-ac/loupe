<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\WorkerWaitEnded;
use App\Module\Workflow\Contract\Actor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Writes one `wait-ended` history row for the end of a wait that a person or a change of the card caused. */
#[AsEventListener]
final readonly class WriteCardEventOnWaitEnded
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
    ) {
    }

    public function __invoke(WorkerWaitEnded $event): void
    {
        $card = $this->cards->find($event->cardId);
        if (null === $card) {
            return;
        }

        $this->cardEvents->record($card, CardEventKind::WaitEnded, Actor::System, null, [
            'reason' => $event->reason->value,
            'runId' => $event->runId->toRfc4122(),
        ], $event->endedAt);
    }
}
