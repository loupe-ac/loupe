<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\View\PublicLinkShareView;
use App\Module\Project\Entity\Project;

/** Reads the share state of graphs for the Dashboard, with one query for the links of a whole project. */
final readonly class GraphShareStates
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
        private PublicShareGuard $guard,
    ) {
    }

    public function for(InsightsSavedGraph $graph): PublicLinkShareView
    {
        return new PublicLinkShareView($this->publicLinks->findForGraph($graph), $this->guard->blockedBy($graph->project));
    }

    /**
     * @param list<InsightsSavedGraph> $graphs
     *
     * @return array<string, PublicLinkShareView> by graph id
     */
    public function forGraphs(Project $project, array $graphs): array
    {
        $links = $this->publicLinks->findGraphLinks($project);
        $block = $this->guard->blockedBy($project);
        $states = [];
        foreach ($graphs as $graph) {
            $id = (string) $graph->id;
            $states[$id] = new PublicLinkShareView($links[$id] ?? null, $block);
        }

        return $states;
    }
}
