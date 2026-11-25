<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Psr\Log\LoggerInterface;

/** Cancels the live discovery run of a project, and asks the bridges to stop its workers. It does nothing when no run is live. */
final readonly class CancelDiscoveryHandler
{
    /** The failure reason of a run that a person cancelled. */
    public const string REASON = 'cancelled';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
        private WorkerRunRepository $workerRuns,
        private RequestBridgeCommandHandler $requestCommand,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CancelDiscoveryCommand $command): void
    {
        $liveId = $this->discoveryRuns->findLiveIdForProject($command->project);
        $run = null === $liveId ? null : $this->discoveryRuns->find($liveId);
        // A cancel that loses to a report must not stop the worker that reported.
        if (null === $run || !($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, self::REASON, cancelledBy: $command->user))) {
            return;
        }

        // Found by subject, because the work requests of the run are cancelled by now. Outside the project lock, because a stop takes a bridge lock.
        $subject = new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.'));
        foreach ($this->workerRuns->findStoppableOfSubject($command->project, $subject) as $workerRun) {
            try {
                ($this->requestCommand)(new RequestBridgeCommandCommand($workerRun, BridgeCommandKind::StopRun, $command->user));
            } catch (DomainErrors $e) {
                $this->logger->info('readiness.discovery_worker_stop_refused', [
                    'discoveryRunId' => (string) $run->id,
                    'workerRunId' => (string) $workerRun->id,
                    'projectId' => (string) $command->project->id,
                    'errors' => $e->errors,
                ]);
            }
        }
    }
}
