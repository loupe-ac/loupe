<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardsChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/** Tells that a list of cards of one project changed, in one `CardsChanged` event after one `CardChanged` each. */
final readonly class CardChangeAnnouncer
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    /** @param list<Uuid> $cardIds */
    public function updated(Uuid $projectId, array $cardIds): void
    {
        if ([] === $cardIds) {
            return;
        }

        foreach ($cardIds as $cardId) {
            $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false, batched: true));
        }
        $this->events->dispatch(new CardsChanged($projectId, $cardIds));
    }
}
