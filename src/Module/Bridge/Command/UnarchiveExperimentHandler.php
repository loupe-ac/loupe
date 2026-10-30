<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UnarchiveExperimentHandler
{
    public function __construct(
        private ExperimentArchiveRepository $experimentArchives,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** Unarchiving an experiment that has no archive row changes nothing and records nothing. */
    public function __invoke(UnarchiveExperimentCommand $command): void
    {
        // The project lock of an archive, so an archive that commits first is read and removed.
        $removed = $this->em->wrapInTransaction(function () use ($command): bool {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $archive = $this->experimentArchives->findOneByName($command->project, $command->experiment);
            if (null === $archive) {
                return false;
            }

            $this->em->remove($archive);
            $this->em->flush();

            return true;
        });
        if (!$removed) {
            return;
        }

        $this->auditor->record(
            'experiment.unarchived',
            AuditOutcome::Success,
            ['projectId' => (string) $command->project->id, 'experiment' => $command->experiment],
            new AuditSubject('experiment', $command->experiment),
        );
    }
}
