<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Service\SavedGraphConfirmations;
use App\Module\Review\Service\WorkerSession;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Removes a saved graph in two calls: a preview, then the removal with the code of the preview.
 *
 * @phpstan-import-type GraphPreviewPayload from GraphListTool
 * @phpstan-import-type GraphRemovedPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Remove a saved graph from the Dashboard, by its id from graph_list. A saved graph is a tile on the Dashboard of the owner, so remove one only when the owner asks for it. The removal takes two calls. The first call has no confirmation: it removes nothing, and it answers preview true with the graph and a confirmation code. Show the name of the graph to the owner and ask whether to remove it. Only after the owner says yes, call graph_remove again with the same graphId and the confirmation. The code works for one removal only: it is refused when the graph changed since the preview, or when anything removed or restored the graph after the preview, also from the web page, and a new preview makes a new code. A session that a bridge started as a worker has no person beside it, so it cannot remove a graph and is refused at both calls. A removed graph can come back with graph_restore for one day, and the response of the removal gives removedGraphId and undoUntil, the end of that day. The response gives the Dashboard as graph_list answers it.')]
final readonly class GraphRemoveTool
{
    public const string NAME = 'graph_remove';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphFinder $finder,
        private SavedGraphConfirmations $confirmations,
        private WorkerSession $workerSession,
        private RemoveSavedGraphHandler $removeGraph,
        private ListSavedGraphsHandler $listGraphs,
    ) {
    }

    /**
     * @param string  $graphId      the id of the graph, from graph_list
     * @param ?string $confirmation the code from the preview, passed after the owner agreed
     *
     * @return GraphPreviewPayload|GraphRemovedPayload
     */
    public function __invoke(string $graphId, ?string $confirmation = null): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            if ($this->workerSession->currentCallIsWorker($project)) {
                throw new ToolCallException('A worker session cannot remove a graph, because no person sits beside it to agree. Ask the owner to remove it, or leave it.');
            }
            $graph = $this->finder->findActive($project, $graphId);

            if (null === $confirmation || '' === $confirmation) {
                $view = ($this->listGraphs)(new ListSavedGraphsCommand($project));

                return [
                    'preview' => true,
                    'graph' => GraphListTool::find($view, $graph) ?? throw new \LogicException('A graph that is not removed is on show.'),
                    'confirmation' => $this->confirmations->issue($graph),
                    'message' => 'Nothing is removed yet. Show the name of the graph to the owner. When the owner agrees, call graph_remove again with this graphId and this confirmation. The code works once.',
                ];
            }
            if (!$this->confirmations->consume($graph, $confirmation)) {
                throw new ToolCallException('confirmation: This code does not fit the graph any more. Call graph_remove with no confirmation to preview the graph again, then ask the owner.');
            }

            try {
                ($this->removeGraph)(new RemoveSavedGraphCommand($graph));
            } catch (NotFoundHttpException) {
                throw new ToolCallException(SavedGraphFinder::GONE);
            }

            return GraphListTool::payload(($this->listGraphs)(new ListSavedGraphsCommand($project))) + [
                'removedGraphId' => $graphId,
                'undoUntil' => ($graph->undoUntil() ?? throw new \LogicException('A removed graph has a removal time.'))->format(\DATE_ATOM),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be removed. The error has been logged.', previous: $e);
        }
    }
}
