<?php

declare(strict_types=1);

namespace App\FeatureFlag;

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

        if (!$this->cacheEnabled()) {
            return $this->loaded = $this->inner->all();
        }

        $hit = apcu_fetch(self::KEY, $found);

        if ($found && is_array($hit)) {
            /* @var array<string, ResolvedFlag> $hit */
            return $this->loaded = $hit;
        }

        $flags = $this->inner->all();
        apcu_store(self::KEY, $flags, self::TTL_SECONDS);

        return $this->loaded = $flags;
    }

    public function reset(): void
    {
        $this->loaded = null;

        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    private function cacheEnabled(): bool
    {
        return 'prod' === $this->environment
            && function_exists('apcu_fetch')
            && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)
            && ('cli' !== PHP_SAPI || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN));
    }
}
