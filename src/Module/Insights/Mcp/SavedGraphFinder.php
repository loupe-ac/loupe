<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Service\SavedGraphLookup;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;

/**
 * Finds a saved graph inside the bound project. A malformed id, a graph of another project and a graph
 * past Undo read the same: a graph that does not exist.
 */
final readonly class SavedGraphFinder
{
    public const string UNKNOWN = 'graphId: No saved graph of this project has this id. Read the ids with graph_list.';

    public const string REMOVED = 'graphId: This graph is removed. Bring it back with graph_restore first.';

    public const string GONE = 'graphId: Another session removed this graph while the call ran. Read the Dashboard with graph_list.';

    public function __construct(
        private SavedGraphLookup $lookup,
    ) {
    }

    /** A graph on show. A removed graph is refused with a message that names graph_restore. */
    public function findActive(Project $project, string $graphId): InsightsSavedGraph
    {
        $graph = $this->findRestorable($project, $graphId);
        if (null === $graph->removedAt) {
            return $graph;
        }

        throw new ToolCallException(self::REMOVED);
    }

    /** A graph on show, or a removed graph that Undo can still bring back. */
    public function findRestorable(Project $project, string $graphId): InsightsSavedGraph
    {
        return $this->lookup->find($project, $graphId) ?? throw new ToolCallException(self::UNKNOWN);
    }
}
