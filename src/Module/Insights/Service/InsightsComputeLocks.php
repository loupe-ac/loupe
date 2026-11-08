<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Uid\Uuid;

/** The compute lock of one item. Only one compute of an item runs at a time, so each compute reads after the one before it ended. */
final readonly class InsightsComputeLocks
{
    /** Seconds. The keeper refreshes the lock just before it keeps. */
    public const int TTL = 300;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    public function forGraph(Uuid $graphId): SharedLockInterface
    {
        return $this->lockFactory->createLock('insights_compute:'.$graphId->toRfc4122(), self::TTL, false);
    }

    public function forExperiment(Project $project, string $experiment): SharedLockInterface
    {
        return $this->lockFactory->createLock('insights_compute:'.$project->requireId()->toRfc4122().':'.$experiment, self::TTL, false);
    }
}
