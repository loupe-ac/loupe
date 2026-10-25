<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers when a user last used a Loupe page. The cache is the database, so
 * every web container and the worker read the same value.
 */
final readonly class PresenceStore
{
    private const int TTL_SECONDS = 120;

    /** A user who posted this recently is looking at Loupe now, so a push would only repeat the page. */
    private const int PRESENT_SECONDS = 90;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function record(User $user): void
    {
        $item = $this->cache->getItem(self::key($user));
        $item->set($this->clock->now()->getTimestamp());
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /** A stamp from the future counts as absent, because every doubt sends the push. */
    public function isPresent(User $user): bool
    {
        $item = $this->cache->getItem(self::key($user));
        $postedAt = $item->get();
        if (!$item->isHit() || !\is_int($postedAt)) {
            return false;
        }

        $age = $this->clock->now()->getTimestamp() - $postedAt;

        return $age >= 0 && $age <= self::PRESENT_SECONDS;
    }

    private static function key(User $user): string
    {
        return 'presence.'.($user->id ?? throw new \LogicException('A stored user has an id.'))->toRfc4122();
    }
}
