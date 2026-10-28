<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;

/** Finds the App installation that acts on a pull request for its project. */
final readonly class GitHubPullRequestInstallations
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubRepositoryInstallations $repositoryInstallations,
    ) {
    }

    /**
     * @return array{int, string} the installation id, and the repository path as the forge spells it
     *
     * @throws GitHubInstallationUnavailable
     */
    public function for(ForgePullRequest $pullRequest): array
    {
        $row = $this->forgeRepositories->findInstallationRowByPath($pullRequest->project, GitHubDelivery::FORGE, $pullRequest->repository);
        if (null === $row) {
            throw new GitHubInstallationUnavailable('no_installation');
        }

        return $this->repositoryInstallations->for($row);
    }

    /** The REST path of a repository, such as `/repos/owner/name`. */
    public static function repositoryPath(string $path): string
    {
        return '/repos/'.implode('/', array_map(rawurlencode(...), explode('/', $path, 2)));
    }
}
