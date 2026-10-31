<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
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
    public function exists(Project $project, string $remoteUrl, string $branch): bool
    {
        $path = GitHubRemoteUrl::pathOf($remoteUrl);
        $row = null === $path ? null : $this->forgeRepositories->findInstallationRowByPath($project, GitHubDelivery::FORGE, $path);
        if (null === $row) {
            return false;
        }

        try {
            [$installationId, $repositoryPath] = $this->installations->for($row);
            $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($repositoryPath).'/branches/'.implode('/', array_map(rawurlencode(...), explode('/', $branch))));
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            if ($e instanceof GitHubAppApiFailed && 'http_status' === $e->reason && 404 === $e->status && !$e->rateLimited) {
                return false;
            }
            $this->logger->warning('github.branch_unreadable', ['projectId' => (string) $project->id, 'repository' => $path, 'reason' => $e->reason]);

            return false;
        }

        return true;
    }
}
