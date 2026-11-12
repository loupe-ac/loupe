<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\Service\InitPullRequestReader;
use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A push to the branch of `loupe init` can open or update the init pull request, which decides whether the merge question is asked. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnForgeBranchPushed
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
    ) {
    }

    public function __invoke(ForgeBranchPushed $event): void
    {
        if (InitPullRequestReader::BRANCH !== $event->branch) {
            return;
        }

        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project);
    }
}
