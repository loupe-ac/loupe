<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The repositories that the App reaches through the installations of a project
 * and that no project holds yet. Each installation list lives in the cache for
 * ten minutes, and a delivery that changes the installation clears it.
 */
final readonly class GitHubFreeRepositories
{
    private const int TTL = 600;

    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubAppApi $appApi,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The repositories are sorted by name. The list is empty and complete when GitHub fails, so the page
     * shows no hint. It is incomplete when an installation holds more repositories than one read lists.
     */
    public function forProject(Project $project): GitHubRepositoryList
    {
        $reached = [];
        $complete = true;
        foreach ($this->gitHubInstallations->findByProject($project) as $installation) {
            if (null !== $installation->removedAt || null !== $installation->suspendedAt) {
                continue;
            }

            try {
                /** @var array{list<array{int, non-empty-string}>, bool} $cached */
                $cached = $this->cache->get(self::cacheKey($installation->installationId), function (ItemInterface $item) use ($installation): array {
                    $item->expiresAfter(self::TTL);

                    $read = $this->appApi->installationRepositories($installation->installationId);

                    return [
                        array_map(static fn (GitHubRepositoryRef $repository): array => [$repository->id, $repository->fullName], $read->repositories),
                        $read->complete,
                    ];
                });
            } catch (GitHubAppApiFailed $failed) {
                $this->logger->info('github.free_repositories_unavailable', ['installationId' => $installation->installationId, 'reason' => $failed->reason]);

                return new GitHubRepositoryList([], true);
            }

            [$list, $listComplete] = $cached;
            $complete = $complete && $listComplete;
            foreach ($list as [$id, $fullName]) {
                $reached[$id] = new GitHubRepositoryRef($id, $fullName);
            }
        }

        $held = $this->forgeRepositories->findInstallationRows(GitHubDelivery::FORGE, array_map(strval(...), array_keys($reached)));
        $free = array_values(array_filter($reached, static fn (GitHubRepositoryRef $repository): bool => !isset($held[$repository->externalId()])));
        usort($free, static fn (GitHubRepositoryRef $a, GitHubRepositoryRef $b): int => strcasecmp($a->fullName, $b->fullName));

        return new GitHubRepositoryList($free, $complete);
    }

    public function forget(int $installationId): void
    {
        $this->cache->delete(self::cacheKey($installationId));
    }

    public static function cacheKey(int $installationId): string
    {
        return 'github_free_repositories.v2.'.$installationId;
    }
}
