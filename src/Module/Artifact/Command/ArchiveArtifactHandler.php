<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ArchiveArtifactHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ArchiveArtifactCommand $command): void
    {
        $artifact = $command->artifact;
        // Archiving twice keeps the first time, which records when it left the list.
        if (null !== $artifact->archivedAt) {
            return;
        }

        $artifact->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditor->record(
            'artifact.archived',
            AuditOutcome::Success,
            ['artifactId' => (string) $artifact->id, 'projectId' => (string) $artifact->project->id],
            new AuditSubject('artifact', (string) $artifact->id),
        );
    }
}
