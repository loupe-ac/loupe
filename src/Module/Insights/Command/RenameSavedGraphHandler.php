<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphLocker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Renames a graph that the Dashboard still holds. A name over the column length is cut. */
final readonly class RenameSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedGraphLocker $locker,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed */
    public function __invoke(RenameSavedGraphCommand $command): void
    {
        $done = $this->em->wrapInTransaction(function () use ($command): bool {
            $graph = $command->graph;
            if (!$this->locker->lockActive($graph)) {
                return false;
            }
            $graph->name = mb_substr(trim($command->name), 0, InsightsSavedGraph::MAX_NAME_LENGTH);

            return true;
        });
        if (!$done) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        $graph = $command->graph;
        $project = $graph->project;
        $projectId = (string) $project->requireId();
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');

        $this->auditor->record(
            'insights.saved_graph_renamed',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId],
            new AuditSubject('project', $projectId),
        );
        $this->dashboard->graphChanged($project, $graphId, DashboardChange::Renamed);
    }
}
