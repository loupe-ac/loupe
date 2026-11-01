<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\MoveSavedGraphCommand;
use App\Module\Insights\Command\MoveSavedGraphHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Moves a saved graph to another place among the graphs on show.
 *
 * @phpstan-import-type GraphAnswerPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Move a saved graph on the Dashboard to another place, by its id from graph_list. A saved graph is a tile on the Dashboard of the owner, so move one only when the owner asks for it. position is the place to take, counted from 0 as graph_list counts it, so 0 is the first tile. A position past the last place puts the graph last, and a negative position is refused. Another session can move a graph between your read and your call, so read the order in the response. The other graphs keep their order. The response gives the graph as graph, and the Dashboard as graph_list answers it.')]
final readonly class GraphMoveTool
{
    public const string NAME = 'graph_move';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphFinder $finder,
        private MoveSavedGraphHandler $moveGraph,
        private ListSavedGraphsHandler $listGraphs,
    ) {
    }

    /**
     * @param string $graphId  the id of the graph, from graph_list
     * @param int    $position the place to take, counted from 0
     *
     * @return GraphAnswerPayload
     */
    public function __invoke(string $graphId, int $position): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            if ($position < 0) {
                throw new ToolCallException('position: Use 0 for the first place, or a larger number.');
            }
            $graph = $this->finder->findActive($project, $graphId);
            $view = ($this->listGraphs)(new ListSavedGraphsCommand($project));
            $steps = $position - (GraphListTool::positionOf($view, $graph) ?? throw new ToolCallException(SavedGraphFinder::GONE));

            if (0 !== $steps) {
                try {
                    ($this->moveGraph)(new MoveSavedGraphCommand($graph, $steps));
                } catch (NotFoundHttpException) {
                    throw new ToolCallException(SavedGraphFinder::GONE);
                }
                $view = ($this->listGraphs)(new ListSavedGraphsCommand($project));
            }

            return GraphListTool::answer($view, $graph);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be moved. The error has been logged.', previous: $e);
        }
    }
}
