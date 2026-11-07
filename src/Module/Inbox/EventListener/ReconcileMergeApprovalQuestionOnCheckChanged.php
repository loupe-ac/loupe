<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A repository check can change whether the rules require a review, which decides the merge question, so the questions of the project match the rules again. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnCheckChanged
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
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
