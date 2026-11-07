<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A repository check can change whether its rules require a review, which decides the merge question, so the open pull requests of the project read their facts again. */
#[AsEventListener]
final readonly class EvaluateCardsOnForgeRepositoryCheckChanged
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(ForgeRepositoryCheckChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $this->trigger->forCards($this->cardPullRequests->findCardIdsWithOpenPullRequests($event->projectId));
    }
}
