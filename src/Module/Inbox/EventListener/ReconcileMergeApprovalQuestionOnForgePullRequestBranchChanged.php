<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Forge\Event\ForgePullRequestBranchChanged;
use App\Module\Forge\Service\InitPullRequestReader;
use App\Module\Inbox\Service\MergeApprovalQuestionReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The init pull request opened, closed, reopened or got a commit, so the merge question follows it. The agent pushes the branch before it opens the pull request. */
#[AsEventListener]
final readonly class ReconcileMergeApprovalQuestionOnForgePullRequestBranchChanged
{
    public function __construct(
        private ProjectRepository $projects,
        private MergeApprovalQuestionReconciler $reconciler,
    ) {
    }

    public function __invoke(ForgePullRequestBranchChanged $event): void
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
