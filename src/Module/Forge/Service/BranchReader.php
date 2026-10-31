<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Answers whether a branch exists in a repository, on one forge. A forge module implements it. */
#[AutoconfigureTag('app.branch_reader')]
interface BranchReader
{
    /**
     * True only when the branch exists in the repository that the git remote URL names, and the project
     * connected that repository. A URL of another forge, an unconnected repository and a forge that cannot
     * answer all read false, so a caller never takes a guess for a branch.
     */
    public function exists(Project $project, string $remoteUrl, string $branch): bool;
}
