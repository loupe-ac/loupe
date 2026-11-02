<?php

declare(strict_types=1);

namespace App\Api\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The request budget of one credential on the public API: reads and writes a minute.
 *
 * The two numbers are integer flags an operator edits without a restart, so the
 * limiter is built per request instead of at container build time.
 */
final readonly class ApiBudget
{
    public const string READS_FLAG = 'api.rate_limit_reads_per_minute';

    public const string WRITES_FLAG = 'api.rate_limit_writes_per_minute';

    public const int DEFAULT_READS = 600;

    public const int DEFAULT_WRITES = 120;

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(service: 'cache.rate_limiter')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function reads(): int
    {
        return $this->positive(self::READS_FLAG, self::DEFAULT_READS);
    }

    public function writes(): int
    {
        return $this->positive(self::WRITES_FLAG, self::DEFAULT_WRITES);
    }

    public function factory(bool $write): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $write ? 'api_budget_write' : 'api_budget_read',
            'policy' => 'sliding_window',
            'limit' => $write ? $this->writes() : $this->reads(),
            'interval' => '1 minute',
        ], new CacheStorage($this->pool));
    }

    /** An operator-typed zero or negative number would lock every credential out, so the coded default stands in. */
    private function positive(string $flag, int $default): int
    {
        $value = $this->featureFlags->getIntValue($flag, $default);

        return $value >= 1 ? $value : $default;
    }
}
