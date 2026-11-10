<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryStanding;
use App\Module\Project\Entity\Project;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tells whether the App reaches one repository path, and which project holds
 * it. The answer of GitHub is cached for ten minutes. The rows are read live.
 */
final readonly class GitHubRepositoryStandings
{
    private const int TTL = 600;

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubAppApi $appApi,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param non-empty-string $path owner/name
     *
     * @throws GitHubAppApiFailed
     */
    public function of(Project $project, string $path): GitHubRepositoryStanding
    {
        $own = $this->forgeRepositories->findInstallationRowByPath($project, GitHubDelivery::FORGE, $path);
        if (null !== $own && null === $own->accessLostAt) {
            return GitHubRepositoryStanding::InProject;
        }

        $parts = explode('/', $path, 2);
        $owner = $parts[0];
        $name = $parts[1] ?? '';
        if ('' === $owner || '' === $name) {
            return GitHubRepositoryStanding::NotShared;
        }
        /** @var ?int $repositoryId */
        $repositoryId = $this->cache->get('github_repository_lookup.'.sha1(strtolower($path)), function (ItemInterface $item) use ($owner, $name): ?int {
            $item->expiresAfter(self::TTL);

            $found = $this->appApi->repositoryInstallation($owner, $name);

            return null === $found ? null : $found[1]->id;
        });
        if (null === $repositoryId) {
            return GitHubRepositoryStanding::NotShared;
        }

        $row = $this->forgeRepositories->findInstallationRow(GitHubDelivery::FORGE, (string) $repositoryId);

        if (null === $row) {
            return GitHubRepositoryStanding::Free;
        }

        // A No access row stays with its project until the reconnect delivery runs.
        return $row->project->id?->equals($project->id) ? GitHubRepositoryStanding::InProject : GitHubRepositoryStanding::OtherProject;
    }
}
