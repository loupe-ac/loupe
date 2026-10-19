<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A child run that opens or closes changes what its epic shows. The negative
 * priority runs after `WriteCardEventOnRunFinished`, so the epic sees the new history row.
 */
#[AsEventListener(priority: -10)]
final readonly class EvaluateParentsOnWorkerRunChanged
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $this->trigger->forCards($this->cards->findParentIds($event->cardIds));
    }
}
