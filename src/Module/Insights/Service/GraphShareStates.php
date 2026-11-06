<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\View\GraphShareView;
use App\Module\Project\Entity\Project;

/** Reads the share state of graphs for the Dashboard, with one query for the links of a whole project. */
final readonly class GraphShareStates
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
        private PublicShareGuard $guard,
    ) {
    }

    public function for(InsightsSavedGraph $graph): GraphShareView
    {
        return new GraphShareView($this->publicLinks->findForGraph($graph), $this->guard->blockedBy($graph->project));
    }

    /**
     * @param list<InsightsSavedGraph> $graphs
     *
     * @return array<string, GraphShareView> by graph id
     */
    public function forGraphs(Project $project, array $graphs): array
    {
        $links = $this->publicLinks->findGraphLinks($project);
        $block = $this->guard->blockedBy($project);
        $states = [];
        foreach ($graphs as $graph) {
            $id = (string) $graph->id;
            $states[$id] = new GraphShareView($links[$id] ?? null, $block);
        }

        return $states;
    }
}
