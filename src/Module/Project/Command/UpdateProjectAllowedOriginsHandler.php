<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Service\SiteOrigins;
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
        $text = $command->origins;
        if (\is_array($text)) {
            foreach ($text as $entry) {
                if (!\is_string($entry)) {
                    throw new DomainErrors(['origins' => 'project.allowed_origins.error.not_a_string']);
                }
                if (1 === preg_match('/\R/', $entry)) {
                    throw new DomainErrors(['origins' => 'project.allowed_origins.error.line_break']);
                }
            }
            $text = implode("\n", $text);
        }
        if (mb_strlen($text) > SiteOrigins::MAX_TEXT_LENGTH) {
            throw new DomainErrors(['origins' => 'project.allowed_origins.error.too_long']);
        }

        $origins = SiteOrigins::normaliseList(preg_split('/\R/', $text) ?: [])
            ?? throw new DomainErrors(['origins' => 'project.allowed_origins.error.invalid']);

        if (\count($origins) > SiteOrigins::MAX) {
            throw new DomainErrors(['origins' => 'project.allowed_origins.error.too_many']);
        }

        $project = $command->project;
        $project->allowedOrigins = $origins;
        $this->em->flush();

        $this->auditor->record(
            'project.allowed_origins_updated',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'count' => \count($project->allowedOrigins)],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
