<?php

declare(strict_types=1);

namespace App\Mcp\Scheduler;

use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Hourly delete of expired MCP session rows from the shared cache table. The
 * read path ignores an expired row, but nothing else removes it.
 */
#[AsCronTask('23 * * * *')]
final readonly class PruneMcpSessionsTask
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
