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

final readonly class ArchiveArtifactHandler
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** Archiving an archived artifact keeps its first timestamp and records nothing. */
    public function __invoke(ArchiveArtifactCommand $command): Artifact
    {
        $artifact = $command->artifact;
        $changed = false;

        // The row lock serializes two callers, so only the one that changes the stored state records an event.
        $this->em->wrapInTransaction(function () use ($artifact, &$changed): void {
            $this->em->lock($artifact, LockMode::PESSIMISTIC_WRITE);
            $stored = $this->artifacts->archivedAtOf($artifact);

            if (null !== $stored) {
                // Synced only when another caller changed the row, so a re-read never drops the sub-second part held here.
                if (null === $artifact->archivedAt) {
                    $artifact->archivedAt = $stored;
                }

                return;
            }

            $artifact->archivedAt = new \DateTimeImmutable();
            $this->em->flush();
            $changed = true;
        });

        if ($changed) {
            $this->auditor->record(
                'artifact.archived',
                AuditOutcome::Success,
                ['artifactId' => (string) $artifact->id, 'projectId' => (string) $artifact->project->id],
                new AuditSubject('artifact', (string) $artifact->id),
            );
        }

        return $artifact;
    }
}
