<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Insights\Service\PublicShareGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Switches the public link of an experiment on or off, and stores its note. The first switch on creates the link and
 * its code. The code never changes after that. A note saved before the first share also creates the link, switched
 * off, so the text is not lost. Sharing an archived experiment unarchives it in the same transaction.
 */
final readonly class SetExperimentPublicLinkHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PublicLinkRepository $publicLinks,
        private PublicShareGuard $guard,
        private ShowExperimentHandler $showExperiment,
        private UnarchiveExperimentHandler $unarchiveExperiment,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return bool true when the share unarchived the experiment
     *
     * @throws DomainErrors          on the field `enabled` when a reason blocks the share
     * @throws NotFoundHttpException when a share names an experiment that the project does not have
     */
    public function __invoke(SetExperimentPublicLinkCommand $command): bool
    {
        $project = $command->project;
        $experiment = $command->experiment;
        $projectId = (string) $project->requireId();
        $note = null === $command->note || '' === trim($command->note) ? null : trim($command->note);

        // Read before the transaction, because a throw inside it closes the entity manager.
        $report = $command->enabled ? ($this->showExperiment)(new ShowExperimentCommand($project, $experiment, withMetrics: false)) : null;
        if ($command->enabled && null === $report) {
            throw new NotFoundHttpException('The project has no such experiment.');
        }

        $block = $this->em->wrapInTransaction(function () use ($command, $project, $experiment, $note): ?PublicShareBlock {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $block = $this->guard->blockedBy($project);
            if (null !== $block) {
                return $block;
            }

            $link = $this->publicLinks->findForExperiment($project, $experiment);
            if (null === $link && ($command->enabled || null !== $note)) {
                $link = PublicLink::forExperiment($project, $experiment);
                $this->em->persist($link);
            }
            if (null !== $link) {
                $link->enabled = $command->enabled;
                $link->note = $note;
            }
            if ($command->enabled) {
                ($this->unarchiveExperiment)(new UnarchiveExperimentCommand($project, $experiment));
            }

            return null;
        });
        if ($block instanceof PublicShareBlock) {
            throw new DomainErrors(['enabled' => $block->translationKey()]);
        }

        $this->auditor->record(
            'insights.public_link_set',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'experiment' => $experiment, 'enabled' => $command->enabled, 'hasNote' => null !== $note],
            new AuditSubject('project', $projectId),
        );

        return null !== $report && $report->archived;
    }
}
