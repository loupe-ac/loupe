<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReader;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRemoteUrl;
use App\Module\Project\Entity\Project;
use Psr\Log\LoggerInterface;

/** Reads a branch as the App installation that delivers the repository to the project. */
final readonly class GitHubBranchReader implements BranchReader
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubRepositoryInstallations $installations,
        private GitHubAppApi $api,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function readableRepository(Project $project, string $remoteUrl): ?ForgeRepository
    {
        $path = GitHubRemoteUrl::pathOf($remoteUrl);

        return null === $path ? null : $this->readableRepositoryAt($project, GitHubDelivery::FORGE, $path);
    }

    #[\Override]
    public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository
    {
        $row = GitHubDelivery::FORGE === $forge ? $this->forgeRepositories->findInstallationRowByPath($project, $forge, $path) : null;
        if (null === $row) {
            return null;
        }

        try {
            $this->installations->for($row);
        } catch (GitHubInstallationUnavailable) {
            return null;
        }

        return $row;
    }

    #[\Override]
    public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence
    {
        if (GitHubDelivery::FORGE !== $repository->forge) {
            return BranchPresence::Unreadable;
        }

        try {
            [$installationId, $repositoryPath] = $this->installations->for($repository);
            $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($repositoryPath).'/branches/'.implode('/', array_map(rawurlencode(...), explode('/', $branch))));
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            if ($e instanceof GitHubAppApiFailed && 'http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return BranchPresence::Absent;
            }
            $this->logger->warning('github.branch_unreadable', ['projectId' => (string) $project->id, 'repository' => $repository->path, 'reason' => $e->reason]);

            return BranchPresence::Unreadable;
        }

        return BranchPresence::Exists;
    }
}
