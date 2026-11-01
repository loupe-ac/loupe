<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Adds a graph to the end of the Dashboard.
 *
 * @phpstan-import-type GraphAnswerPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Save a graph as a new tile at the end of the Dashboard of this project. A saved graph is a tile on the Dashboard of the owner, so save one only when the owner asks for it. metric, unit and statistic are required and take the values of metric_query: metric_list lists each metric with the units, statistics and groups it takes. group defaults to none, range to ninety-days (metric_query defaults to thirty-days), bucket to week, compare to off and chart to line. chart is line, bars, stacked-bars, percent-stacked, donut or number. A setting that the metric or the range cannot take is refused with the values that are valid, and nothing is saved. A chart type that adds the lines together needs lines that add up, so stacked-bars, percent-stacked and donut refuse a metric that is a rate, a statistic that does not add, and a graph with one line. name is at most 120 characters, and a blank name takes the title of the graph. The Dashboard holds a limited number of graphs, shown as limit by graph_list, and a full Dashboard refuses the save. A new graph takes half the width and shows its legend, and graph_update changes both. The response gives the new graph as graph, and the Dashboard as graph_list answers it.')]
final readonly class GraphSaveTool
{
    public const string NAME = 'graph_save';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphSettingsCheck $settingsCheck,
        private SaveSavedGraphHandler $saveGraph,
        private ListSavedGraphsHandler $listGraphs,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string  $metric    the key of a metric from metric_list, such as cost, duration, bucket-time:<name> or harness-events:<kind>
     * @param string  $unit      run, card or reading
     * @param string  $statistic median, mean, sum, p90, count or max
     * @param ?string $name      the name of the tile, at most 120 characters; blank takes the title of the graph
     * @param ?string $group     stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window or none
     * @param ?string $range     seven-days, thirty-days, ninety-days or all
     * @param ?string $bucket    day, week or month; seven-days takes day alone
     * @param ?string $compare   off, or previous to compare with the period just before the range
     * @param ?string $chart     line, bars, stacked-bars, percent-stacked, donut or number
     *
     * @return GraphAnswerPayload
     */
    public function __invoke(string $metric, string $unit, string $statistic, ?string $name = null, ?string $group = null, ?string $range = null, ?string $bucket = null, ?string $compare = null, ?string $chart = null): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            SavedGraphName::check($name);
            $settings = array_filter(
                ['metric' => $metric, 'unit' => $unit, 'statistic' => $statistic, 'group' => $group, 'range' => $range, 'bucket' => $bucket, 'compare' => $compare, 'chart' => $chart],
                static fn (?string $value): bool => null !== $value,
            );
            $this->settingsCheck->check($project, $settings);

            try {
                $saved = ($this->saveGraph)(new SaveSavedGraphCommand($project, $name, $settings));
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e);
            }

            return GraphListTool::answer(($this->listGraphs)(new ListSavedGraphsCommand($project)), $saved->graph);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be saved. The error has been logged.', previous: $e);
        }
    }
}
