<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;

/**
 * Hands a project a push token for its own repository. The repository comes
 * from the project's installation row and never from the caller, and GitHub
 * refuses any other repository because the token names this one alone.
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
    public function issue(Project $project): GitHubScopedToken
    {
        [$installationId, $repositoryId, $permissions] = $this->resolve($project);

        try {
            return $this->api->repositoryToken($installationId, $repositoryId, $permissions);
        } catch (GitHubAppApiFailed) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }
    }

    /** The reason `issue()` would refuse the project now, or null when it would issue a token. */
    public function refusal(Project $project): ?GitHubGitTokenRefused
    {
        try {
            $this->resolve($project);
        } catch (GitHubGitTokenRefused $refused) {
            return $refused;
        }

        return null;
    }

    /**
     * @return array{int, int, array<string, string>} the installation id, the repository id and the permissions to ask for
     *
     * @throws GitHubGitTokenRefused
     */
    private function resolve(Project $project): array
    {
        $installationId = null;
        $repositoryId = null;
        $suspended = false;
        foreach ($this->forgeRepositories->findByProject($project) as $row) {
            $sourceRef = $row->sourceRef;
            if (GitHubDelivery::FORGE !== $row->forge || ForgeRepositorySource::Installation !== $row->source || null === $sourceRef || !ctype_digit($sourceRef)) {
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
            throw new GitHubGitTokenRefused($suspended ? GitHubGitTokenRefused::INSTALLATION_SUSPENDED : GitHubGitTokenRefused::NO_INSTALLATION);
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
