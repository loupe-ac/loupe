<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\CardsChanged;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The cards of one event change in one reconcile, and not in one each. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnCardsChanged
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(CardsChanged $event): void
    {
        $this->trigger->forCards($event->projectId, array_map(strval(...), $event->cardIds));
    }
}
