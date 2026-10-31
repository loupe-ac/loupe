<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardRepository;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\EpicBranches;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A child waits in Next until the epic branch of its parent exists, so a push of that branch evaluates it again. */
#[AsEventListener]
final readonly class EvaluateChildrenOnForgeBranchPushed
{
    public function __construct(
        private CardRepository $cards,
        private EpicBranches $epicBranches,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(ForgeBranchPushed $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $children = array_filter(
            $this->cards->findOpenChildren([$event->projectId->toRfc4122()]),
            fn (array $child): bool => $event->branch === $this->epicBranches->of($event->projectId, $child['parentNumber']),
        );
        $this->trigger->forCards(array_values(array_column($children, 'id')));
    }
}
