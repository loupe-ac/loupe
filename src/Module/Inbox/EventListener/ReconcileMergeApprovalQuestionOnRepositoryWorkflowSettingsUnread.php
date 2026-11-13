<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsUnread;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A forced read of `.loupe.yaml` gave up, so the merge question follows the stored setting and the init pull request. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnRepositoryWorkflowSettingsUnread
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
    ) {
    }

    public function __invoke(RepositoryWorkflowSettingsUnread $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
