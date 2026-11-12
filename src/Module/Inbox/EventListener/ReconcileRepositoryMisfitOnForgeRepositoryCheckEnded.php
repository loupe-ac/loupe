<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Forge\Event\ForgeRepositoryCheckEnded;
use App\Module\Inbox\Service\RepositoryMisfitReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A completed check can leave the gap summary as it was, and a held notice still opens or closes on it, so the notice matches the hold after each one. */
#[AsEventListener]
final readonly class ReconcileRepositoryMisfitOnForgeRepositoryCheckEnded
{
    public function __construct(
        private ProjectRepository $projects,
        private RepositoryMisfitReconciler $reconciler,
    ) {
    }

    public function __invoke(ForgeRepositoryCheckEnded $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
