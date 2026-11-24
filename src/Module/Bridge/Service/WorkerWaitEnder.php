<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkerWaitEnded;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunChangeKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Ends an open wait for a person or for a change of the card. An end that resumes the session asks for the resume
 * when the run is parked, and a live run asks when it ends. Any other end stops a parked run, because nothing resumes it.
 */
final readonly class WorkerWaitEnder
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerWaitRepository $workerWaits,
        private BridgeCommandRepository $bridgeCommands,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private WorkerRunChangedPublisher $publisher,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /** Answers false when the wait had ended already, and changes nothing then. */
    public function end(WorkerWait $wait, WorkerWaitEndReason $reason): bool
    {
        $now = $this->clock->now();
        $waitId = $wait->id ?? throw new \LogicException('A stored wait has an id.');

        // The lock makes the first of two racing ends win. The other one reads the ended wait and changes nothing.
        $parked = $this->em->wrapInTransaction(function () use ($wait, $waitId, $reason, $now): ?bool {
            if (!$this->workerWaits->lockOpen($waitId)) {
                return null;
            }
            if (!$wait->end($reason, $now)) {
                return null;
            }

            $run = $this->workerRuns->find($wait->runId);
            $parked = WorkerRunState::Waiting === $run?->state;
            if ($parked && !$reason->resumesSession()) {
                $run->recordStop($now, null);
                $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Stopped, $now, $now));
            }
            if ($reason->isOwnerOrCardChange()) {
                $this->events->dispatch(new WorkerWaitEnded($wait->cardId, $wait->runId, $reason, $now));
            }
            $this->em->flush();

            return $parked;
        });
        if (null === $parked) {
            return false;
        }
        $run = $this->workerRuns->find($wait->runId);

        if ($parked && $reason->resumesSession()) {
            $this->bus->dispatch(new ResumeWaitingSession($waitId->toRfc4122()));
        }
        $this->announce($wait, $reason, $run, $parked);

        return true;
    }

    /**
     * Stops the parked run of a wait that ended while its bridge was offline, so no resume waits for it any more. The wait keeps its end reason.
     * Answers false when the run is no longer parked, such as after an earlier stop or a resume.
     */
    public function stopStranded(WorkerWait $wait, WorkerWaitEndReason $reason): bool
    {
        $now = $this->clock->now();
        $stopped = $this->em->wrapInTransaction(function () use ($wait, $reason, $now): bool {
            $run = $this->workerRuns->find($wait->runId);
            if (WorkerRunState::Waiting !== $run?->state || null !== $wait->resumeRunId) {
                return false;
            }
            $run->recordStop($now, null);
            $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Stopped, $now, $now));
            // A resume that still waits for the bridge would otherwise wake the session that the owner stopped.
            $this->bridgeCommands->findPendingForRunLocked($run)?->settle(BridgeCommandState::Cancelled, null, $now);
            $this->events->dispatch(new WorkerWaitEnded($wait->cardId, $wait->runId, $reason, $now));
            $this->em->flush();

            return true;
        });
        if (!$stopped) {
            return false;
        }

        $this->announce($wait, $reason, $this->workerRuns->find($wait->runId), true);

        return true;
    }

    /** The card shows no wait now, and a stopped run changes its row. A live run keeps its state. */
    private function announce(WorkerWait $wait, WorkerWaitEndReason $reason, ?WorkerRun $run, bool $parked): void
    {
        $this->publisher->runsChanged($wait->project, WorkerRunChangeKind::State, null === $run ? [] : [$run], [$wait->cardId]);
        $this->events->dispatch(new WorkerRunChanged(
            $wait->project->requireId(),
            [$wait->cardId->toRfc4122()],
            [$wait->runId->toRfc4122()],
        ));

        $this->auditor->record(
            'bridge.worker_wait_ended',
            AuditOutcome::Success,
            [
                'projectId' => $wait->project->requireId()->toRfc4122(),
                'cardId' => $wait->cardId->toRfc4122(),
                'runId' => $wait->runId->toRfc4122(),
                'reason' => $reason->value,
                'runParked' => $parked,
            ],
            new AuditSubject('worker_wait', ($wait->id ?? throw new \LogicException('A stored wait has an id.'))->toRfc4122()),
        );
    }
}
