<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Marks that a `loupe init` session waits for the GitHub App install of one project. The browser return reads the
 * mark to show the hand-off page. The mark lives in the shared cache and ends by itself, so a dead init leaves
 * nothing behind for long.
 */
final readonly class InitInstallWaits
{
    public const int LIFETIME_SECONDS = 300;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** Writes the mark again with a fresh lifetime. */
    public function mark(string $sessionId, string $projectId): void
    {
        $this->cache->save(
            $this->cache->getItem(self::key($sessionId))->set($projectId)->expiresAfter(self::LIFETIME_SECONDS),
        );
    }

    public function clear(string $sessionId): void
    {
        $this->cache->deleteItem(self::key($sessionId));
    }

    public function isWaiting(?string $sessionId, string $projectId): bool
    {
        if (null === $sessionId || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId)) {
            return false;
        }

        $item = $this->cache->getItem(self::key($sessionId));

        return $item->isHit() && $item->get() === $projectId;
    }

    private static function key(string $sessionId): string
    {
        return 'init_wait.'.strtolower($sessionId);
    }
}
