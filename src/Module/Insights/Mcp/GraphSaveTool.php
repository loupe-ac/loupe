<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Mcp\MetricQueryTool;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Service\SavedGraphChangeRefused;
use App\Module\Insights\Service\SavedGraphSettingsMerger;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Adds a graph to the end of the Dashboard.
 *
 * @phpstan-import-type GraphAnswerPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Save a graph as a new tile at the end of the Dashboard of this project. A saved graph is a tile on the Dashboard of the owner, so save one only when the owner asks for it. unit is required, and a graph reads either one metric or a formula. For one metric, pass metric and statistic, which take the values of metric_query: metric_list lists each metric with the units, statistics and groups it takes. For a formula, leave metric and statistic out and pass rows, formula and format as metric_query does: rows is up to six objects with a letter A to F, a metric and a statistic, formula combines the letters with + - * / and parentheses in at most 200 characters, such as A / B, and format is auto, number, percent, money or duration, and auto is the default. The metrics must add up over runs, as metric_query says, and unit and group must be allowed for every row. The graph then holds the formula, and graph_list gives it as formula. The result of a formula does not add up across lines, so stacked-bars, percent-stacked and donut are refused for a formula. group defaults to none, range to ninety-days (metric_query defaults to thirty-days), bucket to week, compare to off and chart to line. chart is line, bars, stacked-bars, percent-stacked, donut or number. A setting that the metric or the range cannot take is refused with the values that are valid, and nothing is saved. A chart type that adds the lines together needs lines that add up, so stacked-bars, percent-stacked and donut refuse a metric that is a rate, a statistic that does not add, and a graph with one line. name is at most 120 characters, and a blank name takes the title of the graph. The Dashboard holds a limited number of graphs, shown as limit by graph_list, and a full Dashboard refuses the save. A new graph takes half the width and shows its legend, and graph_update changes both. The response gives the new graph as graph, and the Dashboard as graph_list answers it.')]
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
     * @param string       $unit      run, card or reading
     * @param ?string      $metric    the key of a metric from metric_list, such as cost, duration, bucket-time:<name> or harness-events:<kind>; leave it out when you pass rows
     * @param ?string      $statistic median, mean, sum, p90, count or max; leave it out when you pass rows
     * @param ?string      $name      the name of the tile, at most 120 characters; blank takes the title of the graph
     * @param ?string      $group     stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window, wake-gap, wake-cache or none
     * @param ?string      $range     seven-days, thirty-days, ninety-days or all
     * @param ?string      $bucket    day, week or month; seven-days takes day alone
     * @param ?string      $compare   off, or previous to compare with the period just before the range
     * @param ?string      $chart     line, bars, stacked-bars, percent-stacked, donut or number
     * @param array<mixed> $rows      the lettered rows of a formula, each with letter, metric and statistic
     * @param ?string      $formula   the formula over the letters of the rows, such as A / B
     * @param ?string      $format    auto, number, percent, money or duration
     *
     * @return GraphAnswerPayload
     */
    public function __invoke(
        string $unit,
        ?string $metric = null,
        ?string $statistic = null,
        ?string $name = null,
        ?string $group = null,
        ?string $range = null,
        ?string $bucket = null,
        ?string $compare = null,
        ?string $chart = null,
        #[Schema(items: MetricQueryTool::ROW_ITEM, maxItems: MetricFormula::MAX_ROWS)] array $rows = [],
        ?string $formula = null,
        ?string $format = null,
    ): array {
        try {
            $project = $this->subjects->requireWritableProject();
            SavedGraphName::check($name);
            $settings = array_filter(
                ['metric' => $metric, 'unit' => $unit, 'statistic' => $statistic, 'group' => $group, 'range' => $range, 'bucket' => $bucket, 'compare' => $compare, 'chart' => $chart, 'rows' => [] === $rows ? null : MetricQueryTool::rowsText($rows), 'formula' => $formula, 'format' => $format],
                static fn (?string $value): bool => null !== $value,
            );
            try {
                SavedGraphSettingsMerger::checkNew($settings);
            } catch (SavedGraphChangeRefused $e) {
                throw new ToolCallException($e->getMessage(), previous: $e);
            }
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
