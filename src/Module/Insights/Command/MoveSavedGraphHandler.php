<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphLocker;
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
        private InsightsSavedGraphRepository $insightsSavedGraphs,
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

        $moved = $this->em->wrapInTransaction(function () use ($command, $graph, $project): ?bool {
            if (!$this->locker->lockActive($graph)) {
                return null;
            }
            $ordered = $this->insightsSavedGraphs->findActive($project);
            $from = array_find_key($ordered, static fn (InsightsSavedGraph $other): bool => $other->id?->equals($graph->id) ?? false);
            if (null === $from) {
                return false;
            }
            $to = max(0, min(\count($ordered) - 1, $from + $command->steps));
            if ($to === $from) {
                return false;
            }

            $positions = array_map(static fn (InsightsSavedGraph $other): int => $other->position, $ordered);
            [$moving] = array_splice($ordered, $from, 1);
            array_splice($ordered, $to, 0, [$moving]);
            foreach ($ordered as $index => $other) {
                $other->position = $positions[$index];
            }

            return true;
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
