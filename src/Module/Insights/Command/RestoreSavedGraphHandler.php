<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphLocker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Puts a removed graph back in its place, unless the Dashboard is full. A graph that is
 * already back stays as it is. Undo checks the cap as a save does, so a remove and a save
 * cannot be followed by an Undo that holds more graphs than the cap allows.
 */
final readonly class RestoreSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedGraphLocker $locker,
        private SavedGraphCap $cap,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainErrors          on the field `name` when the Dashboard holds as many graphs as the cap allows
     * @throws NotFoundHttpException when the graph is gone, or was removed longer ago than the Undo window
     */
    public function __invoke(RestoreSavedGraphCommand $command): void
    {
        $graph = $command->graph;
        $project = $graph->project;
        $projectId = (string) $project->requireId();
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $outcome = $this->em->wrapInTransaction(function () use ($graph, $project, $now): bool|DomainErrors|null {
            if (!$this->locker->lock($graph)) {
                return null;
            }
            if (null === $graph->removedAt) {
                return false;
            }
            if (!$graph->isUndoable($now)) {
                return null;
            }
            try {
                $this->cap->assertRoom($project);
            } catch (DomainErrors $e) {
                return $e;
            }
            $graph->removedAt = null;

            return true;
        });
        if (null === $outcome) {
            throw new NotFoundHttpException('The project has no such graph.');
        }
        if ($outcome instanceof DomainErrors) {
            throw $outcome;
        }
        if (!$outcome) {
            return;
        }

        $this->auditor->record(
            'insights.saved_graph_restored',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graphId],
            new AuditSubject('project', $projectId),
        );
        $this->dashboard->graphChanged($project, $graphId, DashboardChange::Restored);
    }
}
