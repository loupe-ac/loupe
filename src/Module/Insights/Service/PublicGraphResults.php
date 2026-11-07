<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\DashboardGraphResult;

/**
 * The result of a shared graph, with numbered series names. It reads the entry that the Dashboard keeps, so a popular
 * link costs one query for each lifetime of the entry. The kept entry holds the real names, and the numbers apply
 * to the copy that leaves here.
 */
final readonly class PublicGraphResults
{
    public function __construct(
        private SavedGraphHealth $health,
        private DashboardGraphResults $results,
        private PublicSeriesLabels $labels,
    ) {
    }

    /** @return DashboardGraphResult|null null when the graph names something the project no longer has */
    public function forGraph(InsightsSavedGraph $graph): ?DashboardGraphResult
    {
        $project = $graph->project;
        if ([] !== $this->health->check($project, [$graph])) {
            return null;
        }
        $result = $this->results->get($project, $graph) ?? $this->results->compute($project, $graph);

        return $this->labels->apply($project, $result);
    }
}
