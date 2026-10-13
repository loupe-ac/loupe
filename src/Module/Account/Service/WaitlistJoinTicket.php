<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A one-time ticket that lets the join confirmation send its analytics event
 * once per accepted submit. Every accepted submit gets one, saved or not, so
 * the confirmation never tells whether an address was already known. It
 * rides the URL rather than the session, because the waitlist sets no cookie.
 */
final readonly class WaitlistJoinTicket
{
    public const string QUERY_PARAMETER = 't';

    private const int LIFETIME_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function issue(): string
    {
        $ticket = bin2hex(random_bytes(16));
        $this->cache->save(
            $this->cache->getItem(self::key($ticket))->set(true)->expiresAfter(self::LIFETIME_SECONDS),
        );

        return $ticket;
    }

    public function consume(?string $ticket): bool
    {
        if (null === $ticket || 1 !== preg_match('/^[0-9a-f]{32}$/', $ticket)) {
            return false;
        }

        $key = self::key($ticket);
        if (!$this->cache->getItem($key)->isHit()) {
            return false;
        }

        $this->cache->deleteItem($key);

        return true;
    }

    private static function key(string $ticket): string
    {
        return 'waitlist_join_ticket.'.$ticket;
    }
}
