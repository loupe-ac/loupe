<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;

/**
 * Adds up the worker pools that the live bridges of a project report. A bridge
 * serves several projects, so the busy number counts the workers of the whole
 * bridge and reads as full when the bridge is full.
 */
final readonly class AgentsFiguresReader
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private ClockInterface $clock,
    ) {
    }

    /** Null when no live bridge reports worker pools. */
    public function forProject(Project $project): ?AgentsFigures
    {
        $quietBefore = $this->liveness->quietBefore($this->clock->now());
        $busy = 0;
        $limit = 0;
        $reported = false;
        foreach ($this->bridges->findFollowingProject($project) as $bridge) {
            if (null === $bridge->workerPools || $bridge->lastSeenAt < $quietBefore) {
                continue;
            }

            $reported = true;
            foreach ($bridge->workerPools as $pool) {
                $busy += $pool['inUse'];
                $limit += $pool['size'];
            }
        }

        return $reported ? new AgentsFigures($busy, $limit) : null;
    }
}
