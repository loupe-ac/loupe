<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphLocker;
use App\Module\Insights\Service\SavedGraphOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Moves a graph among the graphs on show. The graphs on show reuse their own positions in the new order,
 * so a removed graph keeps its place for Undo. A move that changes nothing sends no signal.
 */
final readonly class MoveSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedGraphOrder $order,
        private SavedGraphLocker $locker,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed */
    public function __invoke(MoveSavedGraphCommand $command): void
    {
        $graph = $command->graph;
        $project = $graph->project;
        $projectId = (string) $project->requireId();
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');

        $moved = $this->em->wrapInTransaction(function () use ($command, $graph): ?bool {
            if (!$this->locker->lockActive($graph)) {
                return null;
            }

            return $this->order->moveBy($graph, $command->steps);
        });
        if (null === $moved) {
            throw new NotFoundHttpException('The project has no such graph.');
        }
        if (!$moved) {
            return;
        }

        $this->auditor->record(
            'insights.saved_graph_moved',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId, 'steps' => $command->steps],
            new AuditSubject('project', $projectId),
        );
        $this->dashboard->graphChanged($project, $graphId, DashboardChange::Moved);
    }
}
