<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Inbox\Service\RepositoryMisfitReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Makes the notice of a project match its current hold, whatever the event says. */
#[AsEventListener]
final readonly class ReconcileRepositoryMisfitOnCheckChanged
{
    public function __construct(
        private ProjectRepository $projects,
        private RepositoryMisfitReconciler $reconciler,
    ) {
    }

    public function __invoke(ForgeRepositoryCheckChanged $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
