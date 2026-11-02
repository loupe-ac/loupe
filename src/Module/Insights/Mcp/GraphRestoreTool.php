<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Brings a removed saved graph back to its place, as Undo does on the Dashboard.
 *
 * @phpstan-import-type GraphAnswerPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Bring a removed saved graph back to its old place on the Dashboard, as Undo does. A graph can come back for one day after its removal. graph_list lists the newest 20 of them in removed, and this tool accepts the id of any graph that was removed less than a day ago, or the removedGraphId that graph_remove answered. A Dashboard that is full refuses the restore: remove a graph first. A graph that is past the day, or that is gone, is refused and cannot come back. A graph that is already on the Dashboard stays as it is. The response gives the graph as graph, and the Dashboard as graph_list answers it.')]
final readonly class GraphRestoreTool
{
    public const string NAME = 'graph_restore';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphFinder $finder,
        private RestoreSavedGraphHandler $restoreGraph,
        private ListSavedGraphsHandler $listGraphs,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $graphId the id of the removed graph, from graph_list or graph_remove
     *
     * @return GraphAnswerPayload
     */
    public function __invoke(string $graphId): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            $graph = $this->finder->findRestorable($project, $graphId);

            try {
                ($this->restoreGraph)(new RestoreSavedGraphCommand($graph));
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e);
            } catch (NotFoundHttpException) {
                throw new ToolCallException('graphId: This graph cannot come back. It is gone, or it was removed more than a day ago.');
            }

            return GraphListTool::answer(($this->listGraphs)(new ListSavedGraphsCommand($project)), $graph);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be restored. The error has been logged.', previous: $e);
        }
    }
}
