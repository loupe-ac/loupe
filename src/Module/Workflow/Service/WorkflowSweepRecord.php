<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers the build that the sweep last ran under and the time of the last full sweep.
 * The cache lives in the database, so the web and the worker containers read one record.
 * A lost record only costs one extra full sweep.
 */
final readonly class WorkflowSweepRecord
{
    private const string BUILD_KEY = 'workflow_sweep_build';

    private const string FULL_KEY = 'workflow_sweep_last_full';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function build(): ?string
    {
        $value = $this->cache->getItem(self::BUILD_KEY)->get();

        return \is_string($value) ? $value : null;
    }

    public function recordBuild(string $version): void
    {
        $this->cache->save($this->cache->getItem(self::BUILD_KEY)->set($version));
    }

    public function lastFullAt(): ?\DateTimeImmutable
    {
        $value = $this->cache->getItem(self::FULL_KEY)->get();

        return \is_int($value) ? new \DateTimeImmutable('@'.$value) : null;
    }

    public function recordFull(\DateTimeImmutable $at): void
    {
        $this->cache->save($this->cache->getItem(self::FULL_KEY)->set($at->getTimestamp()));
    }
}
