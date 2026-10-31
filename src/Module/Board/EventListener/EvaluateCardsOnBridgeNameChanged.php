<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\BridgeNameChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A usage rule names a bridge, so a rename evaluates the cards that rules act on, as a new reading does. */
#[AsEventListener]
final readonly class EvaluateCardsOnBridgeNameChanged
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BridgeNameChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }
        $projectIds = [];
        foreach ($event->projects as $project) {
            if (null !== $project->id) {
                $projectIds[] = $project->id->toRfc4122();
            }
        }
        $this->trigger->forCards($this->cards->findIdsInOpenColumnsOfProjects($projectIds));
    }
}
