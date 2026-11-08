<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\Service\GraphShareStates;
use App\Module\Insights\Service\InsightsProjectActivities;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\KeptGraphView;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\DashboardTileView;
use App\Module\Insights\View\DashboardView;
use Psr\Clock\ClockInterface;

/**
 * Reads the Dashboard. It computes nothing: a tile with no kept result waits, and the page asks the worker for it. A view
 * of a dormant project asks the worker for every graph, and draws the old results with their real age.
 */
final readonly class ShowDashboardHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private DashboardGraphResults $results,
        private KeptGraphView $keptView,
        private SavedGraphCap $cap,
        private GraphShareStates $shares,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
        private InsightsRefreshRequester $refreshes,
        private InsightsProjectActivities $activities,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowDashboardCommand $command): DashboardView
    {
        $project = $command->project;
        $this->activities->recordView((string) $project->requireId());
        $graphs = $this->insightsSavedGraphs->findActive($project);
        $broken = $this->health->check($project, $graphs);

        $shares = $this->shares->forGraphs($project, $graphs);
        $kept = $this->results->forGraphs($graphs);
        $tiles = [];
        $last = \count($graphs) - 1;
        foreach ($graphs as $index => $graph) {
            $problem = $broken[(string) $graph->id] ?? null;
            $result = null === $problem ? ($kept[(string) $graph->id] ?? null) : null;
            if (null === $problem && null === $result) {
                $this->refreshes->graph($graph);
            }
            $tiles[] = new DashboardTileView($graph, $problem, null === $result ? null : $this->keptView->build($result, \Locale::getDefault()), $index > 0, $index < $last, $shares[(string) $graph->id]);
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $computed = array_values(array_filter(array_map(static fn (DashboardTileView $tile): ?\DateTimeImmutable => $tile->result?->computedAt, $tiles)));
        $removed = null;
        if (null !== $command->removedGraphId) {
            $candidate = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->removedGraphId, (string) $project->requireId());
            $removed = null !== $candidate && $candidate->isUndoable($now) ? $candidate : null;
        }

        return new DashboardView(
            $project,
            $tiles,
            $removed,
            $this->cap->isReached($project),
            $this->cap->limit(),
            [] === $computed ? null : min($computed),
            $now,
            $this->publicLinkTakedowns->existsForProject($project),
        );
    }
}
