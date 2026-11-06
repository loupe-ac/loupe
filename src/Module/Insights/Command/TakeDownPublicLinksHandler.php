<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Switches off every public link of a project. A project that is already taken down stays as it is, so a
 * second press changes nothing and records nothing.
 */
final readonly class TakeDownPublicLinksHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
        private PublicLinkRepository $publicLinks,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(TakeDownPublicLinksCommand $command): void
    {
        $project = $command->project;
        if ($this->publicLinkTakedowns->existsForProject($project)) {
            return;
        }

        $projectId = (string) $project->requireId();
        $items = $this->publicLinks->countForProject($project);
        try {
            $this->em->persist(new PublicLinkTakedown($project, $command->actor));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request took the project down first. The state is the one the actor asked for.
            return;
        }

        $this->auditor->record(
            'insights.public_links_taken_down',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'items' => $items],
            new AuditSubject('project', $projectId),
        );
    }
}
