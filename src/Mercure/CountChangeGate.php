<?php

declare(strict_types=1);

namespace App\Mercure;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Ulid;

/**
 * Tells whether a count changed since the last time it was signalled. It keeps
 * a fingerprint of the counts and a version token for each key. A token is a
 * new random value each time, so a lost item cannot bring back a token that a
 * page still holds. Every failure answers true and moves the token.
 */
final readonly class CountChangeGate
{
    /** Seconds. */
    private const int LOCK_TTL = 10;

    /** Seconds. Lets the cache prune the items of a project that no longer exists. */
    private const int ITEM_TTL = 2592000;

    public const string PREFIX = 'count_change_gate.';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(): string $fingerprint reads the counts and joins them into one string
     *
     * @return bool true when the counts differ from the last signalled ones, or when the check failed
     */
    public function changed(string $key, \Closure $fingerprint): bool
    {
        try {
            $lock = $this->locks->createLock(self::PREFIX.$key, self::LOCK_TTL);
            $lock->acquire(true);
            try {
                $current = $fingerprint();
                if ($current === $this->read($key)['fingerprint']) {
                    return false;
                }

                if (!$this->write($key, $current)) {
                    throw new \RuntimeException('The cache refused the new fingerprint.');
                }

                return true;
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            $this->fail($key, $e);

            return true;
        }
    }

    /** Reads the token for the render of a page, and creates it under the lock of the gate when it is missing. Null when it cannot. */
    public function currentVersion(string $key): ?string
    {
        try {
            $version = $this->read($key)['version'];
            if (null !== $version) {
                return $version;
            }

            $lock = $this->locks->createLock(self::PREFIX.$key, self::LOCK_TTL);
            $lock->acquire(true);
            try {
                $item = $this->read($key);
                if (null !== $item['version']) {
                    return $item['version'];
                }

                $version = (string) new Ulid();

                return $this->save($key, $item['fingerprint'], $version) ? $version : null;
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('live_updates.version_unavailable', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Reads the token without creating one. Null when none is stored or the read fails. */
    public function storedVersion(string $key): ?string
    {
        try {
            return $this->read($key)['version'];
        } catch (\Throwable $e) {
            $this->logger->warning('live_updates.version_unavailable', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Sets a new token, with no read first, so two writers at once cannot undo each other. */
    public function replaceVersion(string $key): void
    {
        if (!$this->write($key, null)) {
            $this->fail($key, new \RuntimeException('The cache refused the new version.'));
        }
    }

    /** @return array{fingerprint: ?string, version: ?string} */
    private function read(string $key): array
    {
        $value = $this->cache->getItem(self::PREFIX.$key)->get();
        if (!\is_array($value) || !\is_string($value['version'] ?? null)) {
            return ['fingerprint' => null, 'version' => null];
        }

        return ['fingerprint' => \is_string($value['fingerprint'] ?? null) ? $value['fingerprint'] : null, 'version' => $value['version']];
    }

    /** Stores a new token with the fingerprint, or with none when the caller has none to keep. */
    private function write(string $key, ?string $fingerprint): bool
    {
        return $this->save($key, $fingerprint, (string) new Ulid());
    }

    private function save(string $key, ?string $fingerprint, string $version): bool
    {
        try {
            $item = $this->cache->getItem(self::PREFIX.$key)
                ->set(['fingerprint' => $fingerprint, 'version' => $version])
                ->expiresAfter(self::ITEM_TTL);

            return $this->cache->save($item);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The check failed: the caller signals, so the token moves too, or goes when it cannot move. */
    private function fail(string $key, \Throwable $e): void
    {
        $this->logger->warning('live_updates.count_check_failed', ['key' => $key, 'error' => $e->getMessage()]);
        if ($this->save($key, null, (string) new Ulid())) {
            return;
        }

        try {
            if ($this->cache->deleteItem(self::PREFIX.$key)) {
                return;
            }
        } catch (\Throwable) {
        }

        $this->logger->error('live_updates.version_not_moved', ['key' => $key]);
    }
}
