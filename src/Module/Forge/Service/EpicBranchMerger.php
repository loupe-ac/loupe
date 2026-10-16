<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Merges the default branch into an epic branch, on one forge. A forge module implements it. */
#[AutoconfigureTag('app.epic_branch_merger')]
interface EpicBranchMerger
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * Returns when the epic branch holds the base branch, or when the repository has no epic branch.
     *
     * @param ForgePullRequest $reference a pull request of the repository: it names the project, the forge and the repository
     *
     * @throws EpicBranchMergeFailed when the forge does not take the merge
     */
    public function merge(ForgePullRequest $reference, string $epicBranch, string $baseBranch): void;
}
