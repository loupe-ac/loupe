<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ChangeGitIdentityManagedHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ChangeGitIdentityManagedCommand $command): void
    {
        $project = $command->project;
        if ($project->gitIdentityManaged === $command->managed) {
            return;
        }

        $project->gitIdentityManaged = $command->managed;
        $this->em->flush();

        $this->auditor->record(
            'github.git_identity_managed_changed',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'managed' => $command->managed],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
