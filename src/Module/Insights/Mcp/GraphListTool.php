<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\ListSavedGraphsView;
use App\Module\Insights\Entity\InsightsSavedGraph;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the saved graphs of the Dashboard of the project.
 *
 * @phpstan-type GraphPayload array{id: string, name: string, position: int, width: string, showLegend: bool, settings: array<string, string>, broken: ?array{reason: string, name: string}}
 * @phpstan-type RemovedGraphPayload array{id: string, name: string, undoUntil: string}
 * @phpstan-type GraphListPayload array{graphs: list<GraphPayload>, limit: int, removed: list<RemovedGraphPayload>, removedOmitted: int}
 * @phpstan-type GraphPreviewPayload array{preview: true, graph: GraphPayload, confirmation: string, message: string}
 * @phpstan-type GraphRemovedPayload array{removedGraphId: string, undoUntil: string, graphs: list<GraphPayload>, limit: int, removed: list<RemovedGraphPayload>, removedOmitted: int}
 * @phpstan-type GraphPreviousPayload array{name: string, settings: array<string, string>, width: string, showLegend: bool}
 * @phpstan-type GraphUpdatedPayload array{graph: GraphPayload, previous: GraphPreviousPayload, graphs: list<GraphPayload>, limit: int, removed: list<RemovedGraphPayload>, removedOmitted: int}
 * @phpstan-type GraphAnswerPayload array{graph: GraphPayload, graphs: list<GraphPayload>, limit: int, removed: list<RemovedGraphPayload>, removedOmitted: int}
 */
#[McpTool(name: self::NAME, description: 'List the saved graphs on the Dashboard of this project, in the order the Dashboard draws them. A saved graph is a tile on the Dashboard of the owner. Each graph gives its id, its name, its position (from 0), its width (half or full), showLegend, and its settings: metric, unit, statistic, group, range, bucket, compare and chart. broken is null, or it names what the graph still reads that the project no longer has, such as a bucket that no rule names, and a broken graph draws no data. limit is the most graphs the Dashboard holds. removed lists the newest 20 graphs that were removed less than a day ago and that graph_restore can bring back, with the time until which they can come back. removedOmitted counts the other restorable graphs, which graph_restore still accepts by id. This tool draws no chart. Read the data of a graph with metric_query, and its settings. Change the Dashboard with graph_save, graph_update, graph_move, graph_remove and graph_restore.')]
final readonly class GraphListTool
{
    public const string NAME = 'graph_list';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ListSavedGraphsHandler $listGraphs,
    ) {
    }

    /** @return GraphListPayload */
    public function __invoke(): array
    {
        try {
            $project = $this->subjects->requireReadableProject();

            return self::payload(($this->listGraphs)(new ListSavedGraphsCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The saved graphs could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return GraphListPayload */
    public static function payload(ListSavedGraphsView $view): array
    {
        return [
            'graphs' => array_map(static fn (InsightsSavedGraph $graph, int $position): array => self::graph($view, $graph, $position), $view->graphs, array_keys($view->graphs)),
            'limit' => $view->limit,
            'removed' => array_map(static fn (InsightsSavedGraph $graph): array => [
                'id' => (string) $graph->id,
                'name' => $graph->name,
                'undoUntil' => ($graph->undoUntil() ?? throw new \LogicException('A removed graph has a removal time.'))->format(\DATE_ATOM),
            ], $view->removed),
            'removedOmitted' => $view->removedOmitted,
        ];
    }

    /**
     * The answer of a write: the graph that it touched, then the whole list.
     *
     * @return GraphAnswerPayload
     */
    public static function answer(ListSavedGraphsView $view, InsightsSavedGraph $graph): array
    {
        return ['graph' => self::find($view, $graph) ?? throw new \LogicException('A graph that a write touched is on show.')] + self::payload($view);
    }

    /**
     * The payload of one graph on show, or null when the view does not hold it.
     *
     * @return ?GraphPayload
     */
    public static function find(ListSavedGraphsView $view, InsightsSavedGraph $graph): ?array
    {
        $position = self::positionOf($view, $graph);

        return null === $position ? null : self::graph($view, $graph, $position);
    }

    /** The place of a graph among the graphs on show, counting from 0, or null when it is not on show. */
    public static function positionOf(ListSavedGraphsView $view, InsightsSavedGraph $graph): ?int
    {
        return array_find_key($view->graphs, static fn (InsightsSavedGraph $other): bool => $other->id?->equals($graph->id) ?? false);
    }

    /** @return GraphPayload */
    private static function graph(ListSavedGraphsView $view, InsightsSavedGraph $graph, int $position): array
    {
        $broken = $view->broken[(string) $graph->id] ?? null;

        return [
            'id' => (string) $graph->id,
            'name' => $graph->name,
            'position' => $position,
            'width' => $graph->width->value,
            'showLegend' => $graph->showLegend,
            'settings' => $graph->settings(),
            'broken' => null === $broken ? null : ['reason' => $broken->reason->value, 'name' => $broken->name],
        ];
    }
}
