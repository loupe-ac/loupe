<?php

declare(strict_types=1);

namespace App\Module\Project\Contract;

use Symfony\Component\Uid\Uuid;

/** The pull requests that write an owner's answer to the merge question into `.loupe.yaml`. */
interface PendingMergeApprovalPullRequests
{
    /**
     * @param list<Uuid> $projectIds
     *
     * @return array<string, string> the address of the pull request by repository id, for each answered question that has one
     */
    public function forProjects(array $projectIds): array;
}
