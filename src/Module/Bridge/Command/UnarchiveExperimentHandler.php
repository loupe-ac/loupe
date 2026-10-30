<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\ExperimentArchiveRepository;
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
        $archive = $this->experimentArchives->findOneByName($command->project, $command->experiment);
        if (null === $archive) {
            return;
        }

        $this->em->remove($archive);
        $this->em->flush();

        $this->auditor->record(
            'experiment.unarchived',
            AuditOutcome::Success,
            ['projectId' => (string) $command->project->id, 'experiment' => $command->experiment],
            new AuditSubject('experiment', $command->experiment),
        );
    }
}
