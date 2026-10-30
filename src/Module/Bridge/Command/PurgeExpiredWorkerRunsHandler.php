<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\ExperimentArchive;
use App\Module\Bridge\Experiment\ExperimentArchiveState;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Deletes the run rows, the experiment pins and the host samples the retention
 * window no longer covers, and answers how many runs went. It also deletes the
 * archive rows that no longer hide an experiment.
 */
final readonly class PurgeExpiredWorkerRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private ExperimentArchiveRepository $experimentArchives,
        private BridgeHostSampleRepository $bridgeHostSamples,
        private WorkerRunRetentionPolicy $retention,
        private ClockInterface $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(PurgeExpiredWorkerRunsCommand $command): int
    {
        $cutoff = $this->clock->now()->sub(new \DateInterval('P'.$this->retention->retentionDays().'D'));

        $this->deleteActiveArchives();

        $this->experimentPins->deleteUpdatedBefore($cutoff);
        $this->bridgeHostSamples->deleteSampledBefore($cutoff);

        $deleted = $this->workerRuns->deleteReceivedBefore($cutoff);

        $this->experimentArchives->deleteWithoutExperiment();

        return $deleted;
    }

    /**
     * An archive row that a new run made inactive goes before the run can age
     * out, so the experiment stays back. The unlocked read only picks the
     * candidates. Each delete takes the project lock of an Archive action and
     * reads the row again, so an Archive action during the sweep stays.
     */
    private function deleteActiveArchives(): void
    {
        $candidates = array_filter(
            $this->experimentArchives->findAllRows(),
            fn (ExperimentArchive $archive): bool => !$this->isArchived($archive),
        );

        foreach ($candidates as $candidate) {
            $project = $candidate->project;
            $experiment = $candidate->experiment;
            $this->em->wrapInTransaction(function () use ($project, $experiment): void {
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                $archive = $this->experimentArchives->findOneByName($project, $experiment);
                if (null !== $archive && !$this->isArchived($archive)) {
                    $this->em->remove($archive);
                    $this->em->flush();
                }
            });
        }
    }

    private function isArchived(ExperimentArchive $archive): bool
    {
        return ExperimentArchiveState::isArchived(
            $archive,
            $this->workerRuns->findExperimentRunIdsReceivedFrom($archive->project, $archive->experiment, $archive->archivedAt),
        );
    }
}
