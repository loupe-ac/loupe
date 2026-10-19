<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An epic shows the state kinds of the markers of its children, so a new marker set on a child queues a check of its parent.
 * The parent has no parent, so its own marker write queues nothing and the chain ends.
 */
#[AsEventListener]
final readonly class EvaluateParentOnChildMarkersChanged
{
    public function __construct(
        private CardEvaluations $trigger,
        private CardRepository $cards,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (!$event->markersOnly || !$this->trigger->isOn()) {
            return;
        }
        $parentId = $this->cards->find($event->cardId)?->parent?->id;
        if (null !== $parentId) {
            $this->trigger->forCards([$parentId]);
        }
    }
}
