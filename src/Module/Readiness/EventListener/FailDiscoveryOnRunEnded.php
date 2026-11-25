<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A discovery worker ended while its run still waited for a report or a revision, so the run failed. */
#[AsEventListener]
final readonly class FailDiscoveryOnRunEnded
{
    public const string WORKER_ENDED = 'worker-ended';

    /** The work goes on in another run: a newer event took the place, or the bridge gave its claim back so the request opens again. */
    private const array CONTINUING = [WorkerRunState::Replaced, WorkerRunState::Skipped, WorkerRunState::Dropped, WorkerRunState::UsageLimit, WorkerRunState::Network];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private DiscoveryRunRepository $discoveryRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($event->runIds as $workerRunId) {
            $workerRun = $this->workerRuns->find($workerRunId);
            if (null === $workerRun || DiscoveryRun::SUBJECT_TYPE !== $workerRun->subjectType
                || !\in_array($workerRun->ruleId, [FailDiscoveryRunHandler::RULE_ID, FailDiscoveryRunHandler::REVISE_RULE_ID], true)
                || $workerRun->state->isOpen() || \in_array($workerRun->state, self::CONTINUING, true)) {
                continue;
            }
            // The app alone cancels a request of a discovery run, and it moves the run itself when it must.
            $request = null === $workerRun->workRequestId ? null : $this->workRequests->find($workerRun->workRequestId);
            if (WorkRequestState::Cancelled === $request?->state) {
                continue;
            }

            $run = $this->discoveryRuns->find($workerRun->subjectId);
            if (null !== $run) {
                ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, $workerRun->failureReason ?? self::WORKER_ENDED, $workerRun->ruleId));
            }
        }
    }
}
