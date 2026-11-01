<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphConfirmations;
use App\Module\Insights\Service\SavedGraphLocker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Takes a graph off the Dashboard and keeps its row for a day, so Undo is one write.
 * The same write deletes the graphs of the project that were removed more than a day ago.
 */
final readonly class RemoveSavedGraphHandler
{
    public const string CONFIRMATION_REFUSED = 'insights.saved_graph.error.confirmation_refused';

    public function __construct(
        private EntityManagerInterface $em,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphLocker $locker,
        private ClockInterface $clock,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
        private SavedGraphConfirmations $confirmations,
    ) {
    }

    /**
     * @throws DomainErrors          on the field `confirmation` when the code does not fit the graph as it is now
     * @throws NotFoundHttpException when the graph is already removed
     */
    public function __invoke(RemoveSavedGraphCommand $command): void
    {
        $graph = $command->graph;
        $project = $graph->project;
        $projectId = (string) $project->requireId();
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $outcome = $this->em->wrapInTransaction(function () use ($command, $graph, $project, $now): ?bool {
            if (!$this->locker->lockActive($graph)) {
                return null;
            }
            if (null !== $command->confirmation && !$this->confirmations->consume($graph, $command->confirmation)) {
                return false;
            }
            $this->insightsSavedGraphs->deleteRemovedBefore($project, $now->modify(InsightsSavedGraph::UNDO_WINDOW));
            $graph->removedAt = $now;
            $this->confirmations->forget($graph);

            return true;
        });
        if (null === $outcome) {
            throw new NotFoundHttpException('The project has no such graph.');
        }
        if (!$outcome) {
            throw new DomainErrors(['confirmation' => self::CONFIRMATION_REFUSED]);
        }

        $this->auditor->record(
            'insights.saved_graph_removed',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId],
            new AuditSubject('project', $projectId),
        );
        $this->dashboard->graphChanged($project, $graphId, DashboardChange::Removed);
    }
}
