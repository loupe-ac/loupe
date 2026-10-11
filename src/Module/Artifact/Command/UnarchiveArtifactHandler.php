<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UnarchiveArtifactHandler
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** Restoring a live artifact changes nothing and records nothing. */
    public function __invoke(UnarchiveArtifactCommand $command): Artifact
    {
        $artifact = $command->artifact;
        $changed = false;

        // The row lock serializes two callers, so only the one that changes the stored state records an event.
        $this->em->wrapInTransaction(function () use ($artifact, &$changed): void {
            $this->em->lock($artifact, LockMode::PESSIMISTIC_WRITE);
            $stored = $this->artifacts->archivedAtOf($artifact);

            if (null === $stored) {
                $artifact->archivedAt = null;

                return;
            }

            $artifact->archivedAt = null;
            $this->em->flush();
            $changed = true;
        });

        if ($changed) {
            $this->auditor->record(
                'artifact.unarchived',
                AuditOutcome::Success,
                ['artifactId' => (string) $artifact->id, 'projectId' => (string) $artifact->project->id],
                new AuditSubject('artifact', (string) $artifact->id),
            );
        }

        return $artifact;
    }
}
