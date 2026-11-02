<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Service\AllowedOriginsInput;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateProjectAllowedOriginsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateProjectAllowedOriginsCommand $command): void
    {
        $project = $command->project;
        $project->allowedOrigins = AllowedOriginsInput::normalise($command->origins);
        $this->em->flush();

        $this->auditor->record(
            'project.allowed_origins_updated',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'count' => \count($project->allowedOrigins)],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
