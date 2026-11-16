<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Reads a branch in a repository on one forge. A forge module implements it. */
#[AutoconfigureTag('app.branch_reader')]
interface BranchReader
{
    /**
     * The repository row that the git remote URL names, only when the project can read it now through
     * its forge access. A URL of another forge, an unconnected repository and a lost access answer null.
     * It reads the database and makes no network call.
     */
    public function readableRepository(Project $project, string $remoteUrl): ?ForgeRepository;

    /** The same rule as readableRepository(), for a caller that has the forge and the repository path. */
    public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository;

    /**
     * Asks the forge about one branch. Only a clear "no such branch" answers Absent. A missing access,
     * a rate limit and every other failure answer Unreadable, so a caller never takes a guess for a branch.
     */
    public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence;
}
