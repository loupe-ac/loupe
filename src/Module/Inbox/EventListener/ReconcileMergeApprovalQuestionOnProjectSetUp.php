<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Contract\Event\ProjectSetUp;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A project that is not set up opens no merge question, so the project checks its repositories once when it becomes set up. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnProjectSetUp
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
    ) {
    }

    public function __invoke(ProjectSetUp $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
