<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Workflow\Contract\WaitConditions;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Moves the time limit of an open wait. The limit counts from the start of the wait and stays within the longest wait of the
 * template. The next evaluation of the card reads the new due time from the wait facts.
 */
final readonly class ChangeWaitLimitHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WaitConditions $waitConditions,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ChangeWaitLimitCommand $command): void
    {
        $wait = $this->workerWaits->findOpenOfCard($command->cardId);
        $projectId = $command->project->requireId();
        if (null === $wait || !$wait->project->requireId()->equals($projectId)) {
            throw new DomainErrors(['minutes' => 'bridge.wait.error.no_open_wait']);
        }

        if ($command->minutes > $this->waitConditions->settingsOf($projectId)->maxMinutes) {
            throw new DomainErrors(['minutes' => 'bridge.wait.error.limit_over_maximum']);
        }
        $limitAt = $wait->openedAt->modify(\sprintf('+%d minutes', $command->minutes));
        if ($limitAt <= $this->clock->now()) {
            throw new DomainErrors(['minutes' => 'bridge.wait.error.limit_passed']);
        }

        // The lock keeps a racing end from landing between the check and the change.
        $before = $wait->limitAt;
        $changed = $this->em->wrapInTransaction(function () use ($wait, $limitAt): bool {
            if (!$this->workerWaits->lockOpen($wait->id ?? throw new \LogicException('A stored wait has an id.'))) {
                return false;
            }
            $wait->limitAt = $limitAt;
            $this->em->flush();

            return true;
        });
        if (!$changed) {
            throw new DomainErrors(['minutes' => 'bridge.wait.error.no_open_wait']);
        }

        $this->events->dispatch(new WorkerRunChanged($projectId, [$wait->cardId->toRfc4122()], [$wait->runId->toRfc4122()]));
        $this->auditor->record(
            'bridge.worker_wait_limit_changed',
            AuditOutcome::Success,
            [
                'projectId' => $projectId->toRfc4122(),
                'cardId' => $wait->cardId->toRfc4122(),
                'runId' => $wait->runId->toRfc4122(),
                'limitMinutes' => $command->minutes,
                'wasMinutes' => intdiv($before->getTimestamp() - $wait->openedAt->getTimestamp(), 60),
            ],
            new AuditSubject('worker_wait', (string) $wait->id),
        );
    }
}
