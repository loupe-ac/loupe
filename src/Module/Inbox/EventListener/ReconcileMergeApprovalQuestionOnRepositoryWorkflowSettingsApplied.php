<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsApplied;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Makes the merge questions of a project match its repositories after a read of `.loupe.yaml`, whatever the event says. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnRepositoryWorkflowSettingsApplied
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
    ) {
    }

    public function __invoke(RepositoryWorkflowSettingsApplied $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
