<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Clock\ClockInterface;

/**
 * No heartbeat marks the moment a bridge goes quiet, so the minute sweep looks
 * for the bridges that crossed the line since a recent tick. The Agents pill
 * then drops their workers. The window spans two ticks, so a late tick misses
 * nothing and a second reload is harmless.
 */
final readonly class AnnounceQuietBridgesHandler
{
    public const int WINDOW_SECONDS = 120;

    public function __construct(
        private BridgeRepository $bridges,
        private ProjectRepository $projects,
        private BridgeLiveness $liveness,
        private WorkerRunChangedPublisher $publisher,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Project> the projects told to reload */
    public function __invoke(AnnounceQuietBridgesCommand $command): array
    {
        $quietBefore = $this->liveness->quietBefore($this->clock->now());
        $from = $quietBefore->modify(\sprintf('-%d seconds', self::WINDOW_SECONDS));

        $told = [];
        foreach ($this->bridges->findWithPoolsLastSeenBetween($from, $quietBefore) as $bridge) {
            foreach ($this->projects->findOwnedBy($bridge->owner, $bridge->projects) as $project) {
                $told[(string) $project->id] = $project;
            }
        }
        foreach ($told as $project) {
            $this->publisher->runsChanged($project);
        }

        return array_values($told);
    }
}
