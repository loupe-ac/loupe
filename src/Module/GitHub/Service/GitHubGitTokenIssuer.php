<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;

/**
 * Hands a project a push token for one of its own repositories. The caller
 * names the repository, and the issuer serves it only from an installation row
 * of the project. GitHub refuses any other repository, because the token names
 * this one alone.
 */
final readonly class GitHubGitTokenIssuer
{
    private const array REQUIRED_GRANTS = ['contents', 'pull_requests'];
    private const string OPTIONAL_GRANT = 'workflows';

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubInstallationRepository $gitHubInstallations,
        private GitHubAppApi $api,
    ) {
    }

    /** @throws GitHubGitTokenRefused */
    public function issue(Project $project, string $repositoryPath): GitHubScopedToken
    {
        [$installationId, $repositoryId, $permissions] = $this->resolve($project, $repositoryPath);

        try {
            return $this->api->repositoryToken($installationId, $repositoryId, $permissions);
        } catch (GitHubAppApiFailed) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }
    }

    /** The reason `issue()` would refuse every repository of the project now, or null when one would get a token. */
    public function refusal(Project $project): ?GitHubGitTokenRefused
    {
        try {
            $this->resolve($project, null);
        } catch (GitHubGitTokenRefused $refused) {
            return $refused;
        }

        return null;
    }

    /**
     * @param ?string $repositoryPath the `owner/name` to serve, or null for any repository of the project
     *
     * @return array{int, int, array<string, string>} the installation id, the repository id and the permissions to ask for
     *
     * @throws GitHubGitTokenRefused
     */
    private function resolve(Project $project, ?string $repositoryPath): array
    {
        $installationId = null;
        $repositoryId = null;
        $suspended = false;
        $otherRepository = false;
        foreach ($this->forgeRepositories->findByProject($project) as $row) {
            $sourceRef = $row->sourceRef;
            if (GitHubDelivery::FORGE !== $row->forge || ForgeRepositorySource::Installation !== $row->source || null === $sourceRef || !ctype_digit($sourceRef)) {
                continue;
            }

            if (null !== $repositoryPath && 0 !== strcasecmp($row->path, $repositoryPath)) {
                $otherRepository = true;
                continue;
            }

            $installation = $this->gitHubInstallations->findOneByInstallationId((int) $sourceRef);
            if (null === $installation || null !== $installation->removedAt || !$installation->project->id?->equals($project->id)) {
                continue;
            }

            if (null !== $installation->suspendedAt) {
                $suspended = true;
                continue;
            }

            if (!ctype_digit($row->externalId)) {
                continue;
            }

            $installationId = $installation->installationId;
            $repositoryId = (int) $row->externalId;
            break;
        }

        if (null === $installationId || null === $repositoryId) {
            throw new GitHubGitTokenRefused(match (true) {
                $suspended => GitHubGitTokenRefused::INSTALLATION_SUSPENDED, $otherRepository => GitHubGitTokenRefused::NO_REPOSITORY, default => GitHubGitTokenRefused::NO_INSTALLATION,
            });
        }

        try {
            $installations = $this->api->installations();
        } catch (GitHubAppApiFailed) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }
        $access = array_find($installations, fn ($candidate) => $candidate->id === $installationId);

        if (null === $access) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::NO_INSTALLATION);
        }

        $missing = $access->missingWriteAccess(self::REQUIRED_GRANTS);
        if ([] !== $missing) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::MISSING_GRANT, $missing);
        }

        $permissions = array_fill_keys(self::REQUIRED_GRANTS, 'write');
        if ([] === $access->missingWriteAccess([self::OPTIONAL_GRANT])) {
            $permissions[self::OPTIONAL_GRANT] = 'write';
        }

        return [$installationId, $repositoryId, $permissions];
    }
}
