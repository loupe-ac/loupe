<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\KeptResultRow;

/**
 * Reads the kept results of the graphs, with no language in them. It computes nothing. A row of other settings than the
 * graph holds now counts as missing, so a settings edit shows a waiting tile until the worker keeps the new result.
 */
final readonly class DashboardGraphResults
{
    public function __construct(
        private InsightsKeptResultRepository $insightsKeptResults,
    ) {
    }

    /** The kept result, or null. */
    public function get(InsightsSavedGraph $graph): ?KeptGraphResult
    {
        $row = $this->insightsKeptResults->findForGraph($graph);

        return null === $row ? null : $this->read($graph, $row);
    }

    /**
     * @param list<InsightsSavedGraph> $graphs
     *
     * @return array<string, KeptGraphResult> the kept results by graph id, for the graphs that have a current one
     */
    public function forGraphs(array $graphs): array
    {
        $rows = $this->insightsKeptResults->findForGraphs($graphs);
        $results = [];
        foreach ($graphs as $graph) {
            $row = $rows[(string) $graph->id] ?? null;
            $result = null === $row ? null : $this->read($graph, $row);
            if (null !== $result) {
                $results[(string) $graph->id] = $result;
            }
        }

        return $results;
    }

    private function read(InsightsSavedGraph $graph, KeptResultRow $row): ?KeptGraphResult
    {
        if ($row->settingsHash !== InsightsKeptResult::hashOf($graph)) {
            return null;
        }
        $result = $row->result();

        return $result instanceof KeptGraphResult ? $result : null;
    }
}
