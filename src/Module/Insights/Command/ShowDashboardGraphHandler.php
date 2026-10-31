<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\DashboardTileView;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reads one tile for its lazy frame, and computes and keeps the graph when no result is kept. A broken graph computes nothing. */
final readonly class ShowDashboardGraphHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private DashboardGraphResults $results,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed */
    public function __invoke(ShowDashboardGraphCommand $command): DashboardTileView
    {
        $graph = $command->graph;
        $project = $graph->project;
        if (null !== $graph->removedAt) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        $ids = array_map(static fn (\App\Module\Insights\Entity\InsightsSavedGraph $active): string => (string) $active->id, $this->insightsSavedGraphs->findActive($project));
        $index = array_search((string) $graph->id, $ids, true);
        $broken = $this->health->check($project, [$graph])[(string) $graph->id] ?? null;
        $result = null === $broken ? ($this->results->get($project, $graph) ?? $this->results->compute($project, $graph)) : null;

        return new DashboardTileView($graph, $broken, $result, false !== $index && $index > 0, false !== $index && $index < \count($ids) - 1);
    }
}
