<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\WorkRequestAlreadyLive;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\DiscoveryWorkRequestStore;
use App\Module\Readiness\Service\ReadinessChecklist;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Workflow\Template\AppRules;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores a requested discovery run, then opens the work request that offers it to the bridges. */
final readonly class StartDiscoveryHandler
{
    public const string NO_LIVE_BRIDGE = 'readiness.discovery.error.no_bridge';
    public const string NO_CAPABLE_BRIDGE = 'readiness.discovery.error.no_capable_bridge';
    public const string NO_CAPABLE_BRIDGE_APP_PROMPTS = 'readiness.discovery.error.no_capable_bridge_app_prompts';
    public const string BRIDGE_PAUSED = 'readiness.discovery.error.bridge_paused';

    public const string NO_WORKFLOW = 'readiness.discovery.error.no_workflow';

    public const string AUTOMATION_OFF = 'readiness.discovery.error.automation_off';

    /** The kind, the rule id and the prompt of the first request of a run. */
    public const string WORK_KIND = 'discovery';
    public const string CAPABILITY = 'subject-discovery';
    public const string REQUEST_REFUSED = 'request-refused';
    public const string REQUEST_FAILED = 'request-failed';

    public const string SUPERSEDED_ARCHIVE_REASON = 'readiness.discovery.superseded_archive_reason';

    public function __construct(
        private ReadinessChecklist $checklist,
        private DiscoveryRunRepository $discoveryRuns,
        private OpenWorkRequestHandler $openWorkRequest,
        private DiscoveryWorkRequestStore $storeWorkRequest,
        private FailDiscoveryRunHandler $failDiscoveryRun,
        private WorkRequestRepository $workRequests,
        private ManagerRegistry $registry,
        private ArchiveDocumentHandler $archiveDocument,
        private AppRules $appRules,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
        private EventDispatcherInterface $events,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(StartDiscoveryCommand $command): DiscoveryRun
    {
        $project = $command->project;
        // A run that no bridge can take would fail as soon as it opens its request.
        $refusal = $this->checklist->startRefusal($project);
        if (null !== $refusal) {
            $field = match ($refusal) {
                self::NO_WORKFLOW => 'workflow',
                self::AUTOMATION_OFF => 'automation',
                default => 'bridge',
            };
            throw new DomainErrors([$field => $refusal]);
        }

        // The lock makes the check and the new run one step, so two starts cannot both pass. A verdict takes the same project row, so the reads below see each verdict that committed first. A refusal leaves the closure as a value, because a throw closes the EntityManager.
        $superseded = [];
        $run = $this->em->wrapInTransaction(function () use ($project, &$superseded): DiscoveryRun|DiscoveryRunning {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $live = $this->discoveryRuns->findLiveIdForProject($project);
            if (null !== $live) {
                return new DiscoveryRunning($live);
            }

            foreach ($this->discoveryRuns->findReportedForProject($project) as $reported) {
                $reported->state = DiscoveryRunState::Superseded;
                $reported->endedAt = $this->clock->now();
                $superseded[] = $reported;
            }
            $run = new DiscoveryRun($project, $this->clock->now());
            $this->em->persist($run);
            $this->em->flush();
            foreach ($superseded as $old) {
                $this->events->dispatch(new DiscoveryReportWithdrawn(
                    $project->id ?? throw new \LogicException('A stored project has an id.'),
                    $old->id ?? throw new \LogicException('A stored run has an id.'),
                ));
            }

            return $run;
        });
        if ($run instanceof DiscoveryRunning) {
            throw $run;
        }
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');

        // Not inside a transaction of ours: Bridge announces the request after its own commit. A request that no bridge can take expires inside this call, and the run is then Failed already.
        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $project,
                subject: new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $runId),
                cardNumber: null,
                kind: self::WORK_KIND,
                capability: self::CAPABILITY,
                ruleId: self::WORK_KIND,
                context: new WorkRequestContext(),
                prompt: $this->appRules->prompt(self::WORK_KIND),
                failWhenNoTaker: true,
            ));
        } catch (DomainErrors|WorkRequestAlreadyLive $e) {
            // The unique index behind the open closes the entity manager when it fires.
            $this->resetClosedManager();
            $stored = $this->discoveryRuns->find($runId);
            if (null !== $stored) {
                $this->fail($stored, self::REQUEST_REFUSED);
            }
            $this->retireStored($superseded);

            throw $e;
        } catch (\Throwable $e) {
            $this->resetClosedManager();
            $this->settleAfterThrow($runId);
            $this->retireStored($superseded);

            throw $e;
        }

        // Only the id changes, so a run that failed or was cancelled meanwhile stays Failed, and the store cancels its request.
        $this->storeWorkRequest->store($run, $request, DiscoveryRunState::Requested);
        // Outside the project lock, because a verdict locks the project row and then the document row.
        $this->retire($superseded);

        $this->auditor->record(
            'readiness.discovery_started',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $runId,
                'projectId' => (string) $project->id,
                'workRequestId' => (string) $request->id,
                'supersededCount' => \count($superseded),
                'reporter' => $command->reporter->value,
            ],
            new AuditSubject('discovery_run', (string) $runId),
        );

        return $run;
    }

    /** @param list<DiscoveryRun> $superseded */
    private function retire(array $superseded): void
    {
        foreach ($superseded as $old) {
            if (null !== $old->reportDocument) {
                ($this->archiveDocument)(new ArchiveDocumentCommand($old->reportDocument, $this->translator->trans(self::SUPERSEDED_ARCHIVE_REASON)));
            }
        }
    }

    /**
     * A reset detaches every loaded entity, so this reads the runs again.
     *
     * @param list<DiscoveryRun> $superseded
     */
    private function retireStored(array $superseded): void
    {
        $runs = [];
        foreach ($superseded as $old) {
            $run = $this->discoveryRuns->find($old->id);
            if (null !== $run) {
                $runs[] = $run;
            }
        }
        $this->retire($runs);
    }

    /** A failed transaction closes the entity manager, and the failed start still has to write. */
    private function resetClosedManager(): void
    {
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }
    }

    /** A run with an open or claimed request stays live, because a worker can still report. A request in any other state takes no report. */
    private function settleAfterThrow(Uuid $runId): void
    {
        $run = $this->discoveryRuns->find($runId);
        if (null === $run) {
            return;
        }
        $request = array_last($this->workRequests->findLiveOfSubject(new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $runId)));
        if (null === $request) {
            $this->fail($run, self::REQUEST_FAILED);

            return;
        }
        // The store cancels the request of a run that a cancel ended in the meantime.
        $this->storeWorkRequest->store($run, $request, DiscoveryRunState::Requested);
    }

    /** Fails only a run whose row still says Requested, so a cancel that committed first keeps its reason. */
    private function fail(DiscoveryRun $run, string $reason): void
    {
        ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, $reason, FailDiscoveryRunHandler::RULE_ID));
    }
}
