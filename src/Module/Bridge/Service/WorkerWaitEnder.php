<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkerWaitEnded;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\Repository\WorkerRunRepository;
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
        if (!$wait->end($reason, $now)) {
            return false;
        }

        $waitId = $wait->id ?? throw new \LogicException('A stored wait has an id.');
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

        if ($parked && $reason->resumesSession()) {
            $this->bus->dispatch(new ResumeWaitingSession($waitId->toRfc4122()));
        }
        // The card shows no wait now, and a stopped run changes its row. A live run keeps its state.
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
            new AuditSubject('worker_wait', $waitId->toRfc4122()),
        );

        return true;
    }
}
