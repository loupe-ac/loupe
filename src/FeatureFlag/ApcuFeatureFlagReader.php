<?php

declare(strict_types=1);

namespace App\FeatureFlag;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Dto\ResolvedFlag;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

/**
 * Keeps the whole flag list in APCu for a short time, so a page that reads a
 * flag does not open a database connection on every request. APCu is local to
 * one web container and the admin toggle sends no event, so the lifetime is the
 * only expiry: a change reaches each container within TTL_SECONDS.
 *
 * It caches only in prod, and only when APCu is loaded and enabled. The CLI has
 * APCu off by default, so workers and console commands read the table.
 */
#[AsDecorator(FeatureFlagReaderInterface::class)]
final class ApcuFeatureFlagReader implements FeatureFlagReaderInterface, ResetInterface
{
    public const int TTL_SECONDS = 60;

    private const string KEY = 'app.feature_flags';

    /** @var array<string, ResolvedFlag>|null */
    private ?array $loaded = null;

    public function __construct(
        #[AutowireDecorated]
        private readonly FeatureFlagReaderInterface $inner,

        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,

        #[Autowire(expression: 'null')]
        private ?CacheItemPoolInterface $pool = null,
    ) {
    }

    public function get(string $name): ?ResolvedFlag
    {
        return $this->all()[$name] ?? null;
    }

    public function all(): array
    {
        if (null !== $this->loaded) {
            return $this->loaded;
        }

        $pool = $this->pool();

        if (null === $pool) {
            return $this->loaded = $this->inner->all();
        }

        $item = $pool->getItem(self::KEY);
        $hit = $item->get();

        if ($item->isHit() && is_array($hit)) {
            /* @var array<string, ResolvedFlag> $hit */
            return $this->loaded = $hit;
        }

        $flags = $this->inner->all();
        $item->set($flags)->expiresAfter(self::TTL_SECONDS);
        $pool->save($item);

        return $this->loaded = $flags;
    }

    public function reset(): void
    {
        $this->loaded = null;

        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    private function pool(): ?CacheItemPoolInterface
    {
        if (null !== $this->pool) {
            return $this->pool;
        }

        if ('prod' === $this->environment && ApcuAdapter::isSupported()) {
            return $this->pool = new ApcuAdapter('app_flags');
        }

        return null;
    }
}
