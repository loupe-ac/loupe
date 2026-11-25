<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Event\DiscoveryReportWithdrawn;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Event\DocumentStatusChanged;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** The owner archived the report of a run that waits for a verdict. No verdict can come, so the run ends and a new start is free. */
#[AsEventListener]
final readonly class SupersedeDiscoveryOnReportArchived
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $run = $this->discoveryRuns->findReportedByReportId($event->documentId);
        if (null === $run) {
            return;
        }

        // The start and the verdict take the same project row, so the run they leave behind is the one checked here. The archive dispatches after its commit, so no document lock is held.
        $ended = $this->em->wrapInTransaction(function () use ($run): bool {
            $this->em->lock($run->project, LockMode::PESSIMISTIC_WRITE);
            if (!$this->discoveryRuns->isReportedWithArchivedReport($run)) {
                return false;
            }
            $run->state = DiscoveryRunState::Superseded;
            $run->endedAt = $this->clock->now();
            $this->em->flush();
            $this->events->dispatch(new DiscoveryReportWithdrawn(
                $run->project->id ?? throw new \LogicException('A stored project has an id.'),
                $run->id ?? throw new \LogicException('A stored run has an id.'),
            ));

            return true;
        });
        if (!$ended) {
            return;
        }

        $this->auditor->record(
            'readiness.discovery_report_archived',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $run->id,
                'projectId' => (string) $run->project->id,
                'documentId' => (string) $event->documentId,
            ],
            new AuditSubject('discovery_run', (string) $run->id),
        );
    }
}
