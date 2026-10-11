<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UnarchiveArtifactHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** Restoring a live artifact changes nothing and records nothing. */
    public function __invoke(UnarchiveArtifactCommand $command): Artifact
    {
        $artifact = $command->artifact;
        if (null === $artifact->archivedAt) {
            return $artifact;
        }

        $artifact->archivedAt = null;
        $this->em->flush();

        $this->auditor->record(
            'artifact.unarchived',
            AuditOutcome::Success,
            ['artifactId' => (string) $artifact->id, 'projectId' => (string) $artifact->project->id],
            new AuditSubject('artifact', (string) $artifact->id),
        );

        return $artifact;
    }
}
