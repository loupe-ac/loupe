<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Fails a discovery run while it is requested or revising, and cancels the work requests the run still has open. */
// Lazy, because the withdraw handler reaches every work subject handler, and the discovery one needs this class.
#[Lazy]
final readonly class FailDiscoveryRunHandler
{
    public const string RULE_ID = StartDiscoveryHandler::WORK_KIND;

    public const string REVISE_RULE_ID = 'discovery-revise';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /** Answers whether the run failed now. */
    public function __invoke(FailDiscoveryRunCommand $command): bool
    {
        $run = $command->run;
        $waitsFor = match ($command->ruleId) {
            self::RULE_ID => [DiscoveryRunState::Requested],
            self::REVISE_RULE_ID => [DiscoveryRunState::Revising],
            default => [DiscoveryRunState::Requested, DiscoveryRunState::Revising],
        };

        // The report and the verdict take the same project row, so an approval that commits first leaves a Done run here, never a Failed one.
        $failed = $this->em->wrapInTransaction(function () use ($run, $waitsFor, $command): bool {
            $this->em->lock($run->project, LockMode::PESSIMISTIC_WRITE);
            $fresh = $this->discoveryRuns->freshStateOf($run);
            $run->state = $fresh['state'];
            // Read under the lock, so a report that committed first counts.
            if (null !== $command->workRequestId && \in_array($command->workRequestId->toRfc4122(), $fresh['answeredWorkRequestIds'], true)) {
                return false;
            }
            if (!\in_array($run->state, $waitsFor, true) || !$run->fail($command->reason, $this->clock->now())) {
                return false;
            }
            $this->em->flush();

            return true;
        });
        if (!$failed) {
            return false;
        }

        // A bridge that drops its claim lets a request open again, so a failed run keeps no live request.
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        foreach ($this->workRequests->findLiveOfSubject(new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $runId)) as $request) {
            ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A persisted work request has an id.'), WorkRequestState::Cancelled));
        }

        $this->auditor->record(
            'readiness.discovery_failed',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $runId,
                'projectId' => (string) $run->project->id,
                'reason' => $run->failureReason,
            ],
            new AuditSubject('discovery_run', (string) $runId),
        );

        return true;
    }
}
