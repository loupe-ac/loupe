<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\PublicPreviewRequester;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Insights\Service\PublicShareGuard;
use App\Module\Insights\Service\SavedGraphLocker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Switches the public link of a saved graph on or off. The first switch on creates the link and its code. The code
 * never changes after that, so off and on only change the switch.
 */
final readonly class SetGraphPublicLinkHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PublicLinkRepository $publicLinks,
        private SavedGraphLocker $locker,
        private PublicShareGuard $guard,
        private Auditor $auditor,
        private InsightsRefreshRequester $refreshes,
        private PublicPreviewRequester $previews,
    ) {
    }

    /**
     * @throws DomainErrors          on the field `enabled` when a reason blocks the share
     * @throws NotFoundHttpException when the graph is removed
     */
    public function __invoke(SetGraphPublicLinkCommand $command): void
    {
        $graph = $command->graph;
        $project = $graph->project;
        $projectId = (string) $project->requireId();
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');

        $outcome = $this->em->wrapInTransaction(function () use ($command, $graph, $project): PublicShareBlock|bool {
            if (!$this->locker->lockActive($graph)) {
                return false;
            }
            $block = $this->guard->blockedBy($project);
            if (null !== $block) {
                return $block;
            }
            $link = $this->publicLinks->findForGraph($graph);
            if (null === $link) {
                if ($command->enabled) {
                    $this->em->persist(PublicLink::forGraph($graph));
                }

                return true;
            }
            $link->enabled = $command->enabled;

            return true;
        });
        if (false === $outcome) {
            throw new NotFoundHttpException('The project has no such graph.');
        }
        if ($outcome instanceof PublicShareBlock) {
            throw new DomainErrors(['enabled' => $outcome->translationKey()]);
        }

        $this->auditor->record(
            'insights.public_link_set',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId, 'enabled' => $command->enabled],
            new AuditSubject('project', $projectId),
        );

        if ($command->enabled) {
            $this->refreshes->graph($graph);
            $link = $this->publicLinks->findForGraph($graph);
            if (null !== $link) {
                $this->previews->draw($link->code);
            }
        }
    }
}
