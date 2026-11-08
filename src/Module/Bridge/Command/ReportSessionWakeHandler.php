<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\WorkerRunWakesRecorded;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Gives each started worker run of a session the wake at the same place in the start order, for
 * a run that holds none. A run that holds a wake keeps it. A count that differs, or two runs
 * that start in the same second, write nothing, because no run can then be matched with
 * certainty. The flush rewrites the fact rows.
 */
final readonly class ReportSessionWakeHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReportSessionWakeCommand $command): ReportSessionWakeResult
    {
        $count = \count($command->wakes);
        $projectId = null;
        /** @var ReportSessionWakeResult $result */
        $result = $this->em->wrapInTransaction(function () use ($command, $count, &$projectId): ReportSessionWakeResult {
            // The same lock a state report takes, so the two never write one run at once.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return new ReportSessionWakeResult(null, $count, 0);
            }

            $runs = $this->workerRuns->findStartedOfSessionForUpdate($project, $command->sessionId);
            if (\count($runs) !== $count) {
                return new ReportSessionWakeResult(\count($runs), $count, 0);
            }

            $starts = array_map(static fn (WorkerRun $run): string => $run->startedAt?->format('Y-m-d H:i:s') ?? '', $runs);
            if (\count(array_unique($starts)) !== \count($starts)) {
                return new ReportSessionWakeResult(\count($runs), $count, 0, ambiguousOrder: true);
            }

            foreach ($runs as $i => $run) {
                $lastTurnAt = $command->wakes[$i]?->lastTurnAt;
                if (null !== $lastTurnAt && null !== $run->startedAt && $lastTurnAt > $run->startedAt) {
                    return new ReportSessionWakeResult(\count($runs), $count, 0, lastTurnAfterStart: true);
                }
            }

            $filled = 0;
            foreach ($runs as $i => $run) {
                $wake = $command->wakes[$i];
                if (null !== $wake && null === $run->wakeTrigger) {
                    $run->recordWake($wake);
                    ++$filled;
                }
            }
            $this->em->flush();

            if ($filled > 0) {
                $projectId = (string) $project->id;
            }

            return new ReportSessionWakeResult(\count($runs), $count, $filled);
        });

        if (null !== $projectId) {
            $this->events->dispatch(new WorkerRunWakesRecorded($projectId));
        }

        if (null !== $result->runs) {
            $this->logger->info('bridge.session_wake_reported', [
                'sessionId' => (string) $command->sessionId,
                'runs' => $result->runs,
                'wakes' => $result->wakes,
                'filled' => $result->filled,
                'ambiguousOrder' => $result->ambiguousOrder,
                'lastTurnAfterStart' => $result->lastTurnAfterStart,
            ]);
        }

        return $result;
    }

    private function lockedProject(ReportSessionWakeCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
