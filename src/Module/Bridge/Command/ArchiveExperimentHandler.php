<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\ExperimentArchive;
use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ArchiveExperimentHandler
{
    public function __construct(
        private ExperimentArchiveRepository $experimentArchives,
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /**
     * Archiving an archived experiment moves its archive time forward.
     *
     * @throws DomainErrors when no run and no pin of the project names the experiment
     */
    public function __invoke(ArchiveExperimentCommand $command): void
    {
        $project = $command->project;
        $experiment = $command->experiment;

        // Checked before the transaction, because a throw inside it closes the entity manager.
        if (!$this->workerRuns->experimentHasRun($project, $experiment) && [] === $this->experimentPins->findOfExperiment($project, $experiment)) {
            throw new DomainErrors(['experiment' => 'bridge.experiments.archive.error.unknown']);
        }

        // The lock a first report of a run takes. A run recorded before the
        // archive is covered, and a report that waits for the lock reads its
        // clock after the archive, so it is not.
        $this->em->wrapInTransaction(function () use ($project, $experiment): void {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = $this->clock->now();
            $archivedAt = $now->setTimestamp($now->getTimestamp());
            $covered = $this->workerRuns->findRunIdsReceivedFrom($project, $archivedAt);

            $archive = $this->experimentArchives->findOneByName($project, $experiment);
            if (null === $archive) {
                $this->em->persist(new ExperimentArchive($project, $experiment, $archivedAt, $covered));
            } else {
                $archive->archivedAt = $archivedAt;
                $archive->coveredRunIds = $covered;
            }
            $this->em->flush();
        });

        $this->auditor->record(
            'experiment.archived',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'experiment' => $experiment],
            new AuditSubject('experiment', $experiment),
        );
    }
}
