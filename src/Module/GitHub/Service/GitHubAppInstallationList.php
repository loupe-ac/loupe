<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The installations of the App, kept in the shared cache for ten minutes. A
 * caller that must see GitHub now reads `live()`, which also refreshes the copy.
 * A cache that fails is a miss, and a request never fails because of it.
 */
final readonly class GitHubAppInstallationList
{
    private const string KEY = 'github_app_installation_list';
    private const int TTL = 600;

    public function __construct(
        private GitHubAppApi $api,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @return ?list<GitHubAppInstallationAccess> null when the cache holds no list */
    public function cached(): ?array
    {
        try {
            $item = $this->cache->getItem(self::KEY);
            $value = $item->isHit() ? $item->get() : null;
        } catch (\Exception) {
            return null;
        }

        if (!\is_array($value)) {
            return null;
        }

        $installations = [];
        foreach ($value as $installation) {
            if (!$installation instanceof GitHubAppInstallationAccess) {
                return null;
            }
            $installations[] = $installation;
        }

        return $installations;
    }

    /**
     * @return list<GitHubAppInstallationAccess>
     *
     * @throws GitHubAppApiFailed
     */
    public function live(): array
    {
        $installations = $this->api->installations();

        try {
            $item = $this->cache->getItem(self::KEY);
            $item->set($installations);
            $item->expiresAfter(self::TTL);
            $this->cache->save($item);
        } catch (\Exception) {
        }

        return $installations;
    }

    public function forget(): void
    {
        try {
            $this->cache->deleteItem(self::KEY);
        } catch (\Exception) {
        }
    }
}
