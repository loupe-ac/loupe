<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The kept preview images in the app cache. The small entry of a link holds the version and the PNG hash. The PNG entry
 * holds the bytes, by hash, so a cache that rewrites a whole value on each save never rewrites bytes that did not change.
 */
final readonly class PublicPreviewEntries
{
    public const string ENTRY_PREFIX = 'insights_public_preview_';
    public const string PNG_PREFIX = 'insights_public_preview_png_';

    /** Seconds. */
    public const int ENTRY_TTL = 86400;
    public const int PNG_TTL = 172800;

    /** Seconds. An unchanged PNG is written again only when its entry is older than this. */
    private const int PNG_REWRITE_AFTER = 86400;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /** @phpstan-impure the cache changes between two calls */
    public function entryFor(string $code): ?PublicPreviewEntry
    {
        $value = $this->cache->getItem(self::ENTRY_PREFIX.$code)->get();
        if (!\is_array($value) || !\is_string($value['version'] ?? null) || !\is_string($value['hash'] ?? null) || !\is_int($value['pngWrittenAt'] ?? null)) {
            return null;
        }

        return new PublicPreviewEntry($value['version'], $value['hash'], $value['pngWrittenAt']);
    }

    /** @phpstan-impure the cache changes between two calls */
    public function png(PublicPreviewEntry $entry): ?string
    {
        $png = $this->cache->getItem(self::PNG_PREFIX.$entry->hash)->get();

        return \is_string($png) && '' !== $png ? $png : null;
    }

    /** @return int the bytes written to the PNG entry, zero when the hash guard skipped the write */
    public function keep(string $code, string $version, string $png): int
    {
        $now = $this->clock->now()->getTimestamp();
        $hash = hash('sha256', $png);
        $previous = $this->entryFor($code);

        $written = 0;
        $pngWrittenAt = $previous->pngWrittenAt ?? $now;
        $pngItem = $this->cache->getItem(self::PNG_PREFIX.$hash);
        if ($hash !== $previous?->hash || !$pngItem->isHit() || $now - $previous->pngWrittenAt >= self::PNG_REWRITE_AFTER) {
            $this->cache->save($pngItem->set($png)->expiresAfter(self::PNG_TTL));
            $written = \strlen($png);
            $pngWrittenAt = $now;
        }

        $entry = $this->cache->getItem(self::ENTRY_PREFIX.$code)
            ->set(['version' => $version, 'hash' => $hash, 'pngWrittenAt' => $pngWrittenAt])
            ->expiresAfter(self::ENTRY_TTL);
        $this->cache->save($entry);

        return $written;
    }
}
