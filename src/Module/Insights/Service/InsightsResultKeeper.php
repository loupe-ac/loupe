<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Event\InsightsResultKept;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Insights\View\PublicExperimentResult;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Computes the result of one item under its compute lock and keeps it. The worker waits for the lock. A share page only
 * tries it, and computes without keeping when a worker holds it. A compute whose lock ran out before the keep keeps
 * nothing and queues the item again, because another compute may hold the lock by then.
 */
final readonly class InsightsResultKeeper
{
    public function __construct(
        private InsightsComputeLocks $locks,
        private InsightsKeptResultRepository $insightsKeptResults,
        private KeptGraphComputer $computer,
        private TemplateMetrics $templateMetrics,
        private ShowExperimentHandler $showExperiment,
        private InsightsRefreshRequester $requester,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private EventDispatcherInterface $events,
        private DashboardChangedPublisher $dashboardChanges,
    ) {
    }

    /**
     * The worker path: takes the lock first and reads the graph after it, so a settings change that saved before the
     * lock is in the result. A fresh read drops a graph that the entity manager already holds, so a settings save during the wait counts.
     *
     * @return bool true when the compute kept a new row
     */
    public function graphById(string $projectId, string $graphId): bool
    {
        if (!Uuid::isValid($graphId)) {
            return false;
        }
        $lock = $this->locks->forGraph(Uuid::fromString($graphId));
        $lock->acquire(true);

        try {
            $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($graphId, $projectId, true);
            if (null === $graph || null !== $graph->removedAt || [] !== $this->health->check($graph->project, [$graph])) {
                return false;
            }

            return $this->computeAndKeep($graph, $lock, true)[1];
        } finally {
            $this->release($lock, true);
        }
    }

    /**
     * The share page path: tries the lock and computes in the request. The result is kept only when the page got the lock.
     */
    public function graph(InsightsSavedGraph $graph): KeptGraphResult
    {
        $lock = $this->locks->forGraph($graph->id ?? throw new \LogicException('A stored graph has an id.'));
        $held = $lock->acquire(false);

        try {
            [$result, $kept] = $this->computeAndKeep($graph, $lock, $held);
            if ($kept) {
                $this->dashboardChanges->graphsRefreshed($graph->project->requireId(), [(string) $graph->id]);
            }

            return $result;
        } finally {
            $this->release($lock, $held);
        }
    }

    /** @return array{0: KeptGraphResult, 1: bool} the result, and whether it was kept */
    private function computeAndKeep(InsightsSavedGraph $graph, SharedLockInterface $lock, bool $held): array
    {
        $startedAt = $this->insightsKeptResults->databaseNow();
        $project = $graph->project;
        /** @var InputBag<string> $settings */
        $settings = new InputBag($graph->settings());
        $query = MetricsQuery::fromQuery($settings, $this->templateMetrics->forProject($project->requireId()));
        $result = $this->computer->compute(new ShowMetricsCommand($project, $query, $startedAt));

        $kept = false;
        if ($held && $this->stillHeld($lock)) {
            $kept = true;
            $this->insightsKeptResults->keepGraph($graph, InsightsKeptResult::hashOf($graph), $startedAt, InsightsKeptResult::encode($result));
            $this->events->dispatch(new InsightsResultKept((string) $project->requireId(), (string) $graph->id, null));
        } elseif ($held) {
            $this->requester->graph($graph);
        }

        return [$result, $kept];
    }

    /**
     * @param bool $wait true waits for the compute lock, false computes without keeping when the lock is taken
     *
     * @return PublicExperimentResult|null null when the project has no such experiment
     */
    public function experiment(Project $project, string $experiment, bool $wait): ?PublicExperimentResult
    {
        $lock = $this->locks->forExperiment($project, $experiment);
        $held = $lock->acquire($wait);

        try {
            $startedAt = $this->insightsKeptResults->databaseNow();
            $report = ($this->showExperiment)(new ShowExperimentCommand($project, $experiment, limit: 0));
            if (null === $report) {
                return null;
            }
            $result = new PublicExperimentResult($startedAt, $report->experiment, $report->headline, $report->variants, $report->metrics);

            if ($held && $this->stillHeld($lock)) {
                $this->insightsKeptResults->keepExperiment($project, $experiment, $startedAt, InsightsKeptResult::encode($result));
                $this->events->dispatch(new InsightsResultKept((string) $project->requireId(), null, $experiment));
            } elseif ($held) {
                $this->requester->experiment($project, $experiment);
            }

            return $result;
        } finally {
            $this->release($lock, $held);
        }
    }

    private function stillHeld(SharedLockInterface $lock): bool
    {
        try {
            $lock->refresh();

            return $lock->isAcquired() && !$lock->isExpired();
        } catch (LockException) {
            return false;
        }
    }

    private function release(SharedLockInterface $lock, bool $held): void
    {
        if (!$held) {
            return;
        }
        try {
            $lock->release();
        } catch (LockException) {
            // The lock ran out and another compute took it, so there is nothing to release.
        }
    }
}
