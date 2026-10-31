<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphLocker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Sets how much of the Dashboard row a graph takes. */
final readonly class ResizeSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedGraphLocker $locker,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed */
    public function __invoke(ResizeSavedGraphCommand $command): void
    {
        $done = $this->em->wrapInTransaction(function () use ($command): bool {
            $graph = $command->graph;
            if (!$this->locker->lockActive($graph)) {
                return false;
            }
            $graph->width = $command->width;

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
            'insights.saved_graph_resized',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId, 'width' => $command->width->value],
            new AuditSubject('project', $projectId),
        );
        $this->dashboard->graphChanged($project, $graphId, DashboardChange::Resized);
    }
}
