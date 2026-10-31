<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\BridgeAvailabilityChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A child waits in Next until a live bridge reports a repository that holds the epic branch, so a bridge that changes evaluates it again. */
#[AsEventListener]
final readonly class EvaluateChildrenOnBridgeRepositoriesChanged
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BridgeAvailabilityChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $this->trigger->forCards(array_column($this->cards->findOpenChildren($event->projectIds), 'id'));
    }
}
