<?php

declare(strict_types=1);

namespace App\Cache\Scheduler;

use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Hourly delete of expired rows from the whole shared cache.app pool. The read
 * path ignores an expired row, but nothing else removes it. MCP sessions, rate
 * limiters and the kept Dashboard results all live in this pool.
 */
#[AsCronTask('23 * * * *')]
final readonly class PruneAppCacheTask
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function __invoke(): void
    {
        if ($this->cache instanceof PruneableInterface) {
            $this->cache->prune();
        }
    }
}
