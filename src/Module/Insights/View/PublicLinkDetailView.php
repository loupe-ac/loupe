<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;

/** A shared item as a visitor sees it: a graph or an experiment. */
final readonly class PublicLinkDetailView
{
    private function __construct(
        public Project $project,
        public ?InsightsSavedGraph $graph,
        /** Carries the numbered series names. Null when the graph cannot be drawn right now. */
        public ?DashboardGraphResult $result,
        public ?PublicExperimentResult $experiment,
        /** The sanitized HTML of the note of an experiment link, or null when the owner wrote none. */
        public ?string $noteHtml,
    ) {
    }

    public static function forGraph(Project $project, InsightsSavedGraph $graph, ?DashboardGraphResult $result): self
    {
        return new self($project, $graph, $result, null, null);
    }

    public static function forExperiment(Project $project, PublicExperimentResult $experiment, ?string $noteHtml): self
    {
        return new self($project, null, null, $experiment, $noteHtml);
    }

    public function isExperiment(): bool
    {
        return null !== $this->experiment;
    }

    /** The name of the shared item, for the title of the page. */
    public function itemName(): string
    {
        return $this->graph->name ?? $this->experiment->experiment ?? '';
    }
}
