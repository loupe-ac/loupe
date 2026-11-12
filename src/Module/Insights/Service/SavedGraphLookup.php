<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;

/**
 * Finds a saved graph inside one project. A malformed id, a graph of another project and a graph past Undo
 * read the same: a graph that does not exist. A removed graph inside the Undo window is found, and the caller
 * reads removedAt to tell it from a graph on show.
 */
final readonly class SavedGraphLookup
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private ClockInterface $clock,
    ) {
    }

    public function find(Project $project, string $graphId): ?InsightsSavedGraph
    {
        $graph = $this->findStored($project, $graphId);
        if (null === $graph || (null !== $graph->removedAt && !$graph->isUndoable(\DateTimeImmutable::createFromInterface($this->clock->now())))) {
            return null;
        }

        return $graph;
    }

    /** The graph row of the project, also a removed graph past Undo that the next remove has not deleted yet. */
    public function findStored(Project $project, string $graphId): ?InsightsSavedGraph
    {
        return $this->insightsSavedGraphs->findOneByIdAndProjectId($graphId, (string) $project->id);
    }
}
