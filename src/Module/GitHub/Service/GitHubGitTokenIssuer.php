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
 * this one alone. The list of installations and the tokens come from the
 * shared cache when they can. Every refusal rests on a live answer from GitHub.
 */
final readonly class GitHubGitTokenIssuer
{
    private const array REQUIRED_GRANTS = ['contents', 'pull_requests'];
    private const string OPTIONAL_GRANT = 'workflows';

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubInstallationRepository $gitHubInstallations,
        private GitHubAppApi $api,
        private GitHubAppInstallationList $installationList,
        private GitHubRepositoryTokenStore $tokenStore,
        private GitHubAppConfiguration $configuration,
    ) {
    }

    /** @throws GitHubGitTokenRefused */
    public function issue(Project $project, string $repositoryPath): GitHubScopedToken
    {
        [$installationId, $repositoryId, $permissions, $fromCache] = $this->resolve($project, $repositoryPath, false);

        try {
            return $this->mint($installationId, $repositoryId, $permissions);
        } catch (GitHubAppApiFailed $failed) {
            // A 404 or 422 can mean that a stale list picked a grant that is gone, so only the live list can say.
            if (!$fromCache || $failed->rateLimited || !\in_array($failed->status, [404, 422], true)) {
                throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
            }
        }

        [$liveInstallationId, $liveRepositoryId, $livePermissions] = $this->resolve($project, $repositoryPath, true);
        if ($liveInstallationId === $installationId && $liveRepositoryId === $repositoryId && $livePermissions === $permissions) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }

        try {
            return $this->mint($liveInstallationId, $liveRepositoryId, $livePermissions);
        } catch (GitHubAppApiFailed) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }
    }

    /**
     * @param array<string, string> $permissions
     *
     * @throws GitHubAppApiFailed
     */
    private function mint(int $installationId, int $repositoryId, array $permissions): GitHubScopedToken
    {
        $kept = $this->tokenStore->find($installationId, $repositoryId, $permissions);
        if (null !== $kept) {
            return $kept;
        }

        $token = $this->api->repositoryToken($installationId, $repositoryId, $permissions);
        $this->tokenStore->keep($installationId, $repositoryId, $permissions, $token);

        return $token;
    }

    /** The reason `issue()` would refuse every repository of the project now, or null when one would get a token. */
    public function refusal(Project $project): ?GitHubGitTokenRefused
    {
        try {
            $this->resolve($project, null, false);
        } catch (GitHubGitTokenRefused $refused) {
            return $refused;
        }

        return null;
    }

    /**
     * Serves the first candidate repository whose installation grants the push.
     * When none does, the refusal of the first candidate stands for them all.
     * A refusal never rests on the cached list: the live list decides it.
     *
     * @param ?string $repositoryPath the `owner/name` to serve, or null for any repository of the project
     * @param bool    $live           read the list from GitHub, and not from the cache
     *
     * @return array{int, int, array<string, string>, bool} the installation id, the repository id, the permissions to ask for, and whether the list came from the cache
     *
     * @throws GitHubGitTokenRefused
     */
    private function resolve(Project $project, ?string $repositoryPath, bool $live): array
    {
        if (!$this->configuration->canReadForge()) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::APP_NOT_CONFIGURED);
        }

        $candidates = $this->candidates($project, $repositoryPath);

        try {
            $cached = $live ? null : $this->installationList->cached();
            if (null !== $cached) {
                try {
                    return [...$this->pick($candidates, $cached), true];
                } catch (GitHubGitTokenRefused) {
                    $this->installationList->forget();
                }
            }

            $installations = $this->installationList->live();
        } catch (GitHubAppApiFailed) {
            throw new GitHubGitTokenRefused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE);
        }

        return [...$this->pick($candidates, $installations), false];
    }

    /**
     * @param non-empty-list<array{int, int}>   $candidates
     * @param list<GitHubAppInstallationAccess> $installations
     *
     * @return array{int, int, array<string, string>}
     *
     * @throws GitHubGitTokenRefused
     */
    private function pick(array $candidates, array $installations): array
    {
        $first = null;
        foreach ($candidates as [$installationId, $repositoryId]) {
            $access = array_find($installations, fn ($candidate) => $candidate->id === $installationId);
            $missing = null !== $access ? $access->missingWriteAccess(self::REQUIRED_GRANTS) : [];
            if (null !== $access && [] === $missing) {
                $permissions = array_fill_keys(self::REQUIRED_GRANTS, 'write');
                if ([] === $access->missingWriteAccess([self::OPTIONAL_GRANT])) {
                    $permissions[self::OPTIONAL_GRANT] = 'write';
                }

                return [$installationId, $repositoryId, $permissions];
            }

            $first ??= null === $access
                ? new GitHubGitTokenRefused(GitHubGitTokenRefused::NO_INSTALLATION)
                : new GitHubGitTokenRefused(GitHubGitTokenRefused::MISSING_GRANT, $missing);
        }

        throw $first;
    }

    /**
     * The repositories of the project that live installations serve, as installation id and repository id pairs.
     *
     * @return non-empty-list<array{int, int}>
     *
     * @throws GitHubGitTokenRefused when the project has none
     */
    private function candidates(Project $project, ?string $repositoryPath): array
    {
        $candidates = [];
        $suspended = false;
        $otherRepository = false;
        foreach ($this->forgeRepositories->findByProject($project) as $row) {
            $sourceRef = $row->sourceRef;
            if (GitHubDelivery::FORGE !== $row->forge || ForgeRepositorySource::Installation !== $row->source || null === $sourceRef || !ctype_digit($sourceRef) || null !== $row->accessLostAt) {
                continue;
            }

            if (null !== $repositoryPath && 0 !== strcasecmp($row->path, $repositoryPath)) {
                $otherRepository = true;
                continue;
            }

            $installation = $this->gitHubInstallations->findOneByInstallationId((int) $sourceRef);
            if (null === $installation || null !== $installation->removedAt) {
                continue;
            }

            if (null !== $installation->suspendedAt) {
                $suspended = true;
                continue;
            }

            if (!ctype_digit($row->externalId)) {
                continue;
            }

            $candidates[] = [$installation->installationId, (int) $row->externalId];
        }

        if ([] === $candidates) {
            throw new GitHubGitTokenRefused(match (true) {
                $suspended => GitHubGitTokenRefused::INSTALLATION_SUSPENDED, $otherRepository => GitHubGitTokenRefused::NO_REPOSITORY, default => GitHubGitTokenRefused::NO_INSTALLATION,
            });
        }

        return $candidates;
    }
}
