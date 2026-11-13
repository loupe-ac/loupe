<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Contract\PendingMergeApprovalPullRequests;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PendingMergeApprovalPullRequests::class)]
final readonly class InboxPendingMergeApprovalPullRequests implements PendingMergeApprovalPullRequests
{
    public function __construct(
        private InboxMergeApprovalQuestionRepository $inboxMergeApprovalQuestions,
    ) {
    }

    #[\Override]
    public function forProjects(array $projectIds): array
    {
        return $this->inboxMergeApprovalQuestions->findAnsweredPullRequestUrls($projectIds);
    }
}
