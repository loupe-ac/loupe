<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\ProjectRepositories;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsApplied;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The merge approval of a repository can change after a read of its settings, so its open pull requests read their facts again. */
#[AsEventListener]
final readonly class EvaluateCardsOnRepositoryWorkflowSettingsApplied
{
    public function __construct(
        private ProjectRepositories $repositories,
        private CardPullRequestRepository $cardPullRequests,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(RepositoryWorkflowSettingsApplied $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $repository = array_find($this->repositories->of($event->projectId), static fn ($ref): bool => $ref->id->equals($event->repositoryId));
        if (null === $repository) {
            return;
        }

        $this->trigger->forCards($this->cardPullRequests->findCardIdsWithOpenPullRequests($event->projectId, $repository->path));
    }
}
