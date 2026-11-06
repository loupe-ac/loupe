<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Ends the takedown of a project. The switches of the owner stay as they were, so the links that were on work
 * again. A project with no takedown stays as it is.
 */
final readonly class LiftPublicLinkTakedownHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
        private PublicLinkRepository $publicLinks,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(LiftPublicLinkTakedownCommand $command): void
    {
        $project = $command->project;
        $takedown = $this->publicLinkTakedowns->findForProject($project);
        if (null === $takedown) {
            return;
        }

        $projectId = (string) $project->requireId();
        $items = $this->publicLinks->countForProject($project);
        $this->em->remove($takedown);
        $this->em->flush();

        $this->auditor->record(
            'insights.public_links_restored',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'items' => $items],
            new AuditSubject('project', $projectId),
        );
    }
}
