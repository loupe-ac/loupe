<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\DashboardGraphResult;
use Psr\Clock\ClockInterface;

/**
 * The result of a shared graph, with numbered series names. It reads the result that the worker keeps. A result
 * younger than the sweep interval is used as it is. Otherwise the page computes in its own request, and keeps the
 * result when no worker holds the compute lock. The kept result holds the real names, and the numbers apply to the
 * copy that leaves here.
 */
final readonly class PublicGraphResults
{
    public function __construct(
        private SavedGraphHealth $health,
        private DashboardGraphResults $results,
        private InsightsResultKeeper $keeper,
        private InsightsRefreshSettings $settings,
        private PublicSeriesLabels $labels,
        private KeptGraphView $keptView,
        private ClockInterface $clock,
    ) {
    }

    /** @return DashboardGraphResult|null null when the graph names something the project no longer has */
    public function forGraph(InsightsSavedGraph $graph): ?DashboardGraphResult
    {
        $project = $graph->project;
        if ([] !== $this->health->check($project, [$graph])) {
            return null;
        }
        $kept = $this->results->get($graph);
        if (null === $kept || $kept->computedAt <= $this->clock->now()->modify('-'.$this->settings->sweepIntervalMinutes().' minutes')) {
            $kept = $this->keeper->graph($graph);
        }

        return $this->labels->apply($project, $this->keptView->build($kept, \Locale::getDefault()));
    }
}
