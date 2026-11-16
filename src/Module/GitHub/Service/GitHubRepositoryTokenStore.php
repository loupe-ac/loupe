<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

/**
 * Keeps a push token in the shared cache between requests, encrypted with the
 * key of the encrypted columns. A token is served only while 15 minutes or more
 * of its life remain, so the bridge that receives it has time to use it. With
 * no usable key, or a cache that fails, the store holds nothing and a request
 * asks GitHub as before.
 */
final readonly class GitHubRepositoryTokenStore
{
    private const int MARGIN_SECONDS = 900;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private Encryptor $encryptor,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, string> $permissions */
    public function find(int $installationId, int $repositoryId, array $permissions): ?GitHubScopedToken
    {
        try {
            $item = $this->cache->getItem(self::key($installationId, $repositoryId, $permissions));
            $stored = $item->isHit() ? $item->get() : null;
            if (!\is_string($stored)) {
                return null;
            }

            $data = json_decode($this->encryptor->decrypt($stored)->plain, true, 4, \JSON_THROW_ON_ERROR);
            $token = \is_array($data) ? ($data['token'] ?? null) : null;
            $expiresAt = \is_array($data) ? ($data['expires_at'] ?? null) : null;
            if (!\is_string($token) || '' === $token || !\is_string($expiresAt)) {
                return null;
            }

            $expires = new \DateTimeImmutable($expiresAt);
        } catch (\Exception) {
            return null;
        }

        return $this->clock->now() < $expires->modify('-'.self::MARGIN_SECONDS.' seconds') ? new GitHubScopedToken($token, $expires) : null;
    }

    /** @param array<string, string> $permissions */
    public function keep(int $installationId, int $repositoryId, array $permissions, GitHubScopedToken $token): void
    {
        $lifetime = $token->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp() - self::MARGIN_SECONDS;
        if ($lifetime <= 0) {
            return;
        }

        try {
            $item = $this->cache->getItem(self::key($installationId, $repositoryId, $permissions));
            $item->set($this->encryptor->encrypt(json_encode([
                'token' => $token->token,
                'expires_at' => $token->expiresAt->format(\DATE_ATOM),
            ], \JSON_THROW_ON_ERROR)));
            $item->expiresAfter($lifetime);
            $this->cache->save($item);
        } catch (\Exception) {
        }
    }

    /** @param array<string, string> $permissions */
    private static function key(int $installationId, int $repositoryId, array $permissions): string
    {
        ksort($permissions);

        return 'github_repository_token_'.sha1($installationId.':'.$repositoryId.':'.json_encode($permissions, \JSON_THROW_ON_ERROR));
    }
}
