<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;

/** A shared graph as a visitor sees it. A null result means the graph cannot be drawn right now. */
final readonly class PublicLinkDetailView
{
    public function __construct(
        public Project $project,
        public InsightsSavedGraph $graph,
        /** Carries the numbered series names. */
        public ?DashboardGraphResult $result,
    ) {
    }
}
