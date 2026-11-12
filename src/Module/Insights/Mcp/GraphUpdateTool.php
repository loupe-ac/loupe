<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Mcp\MetricQueryTool;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\SavedGraphSettingsChange;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Service\SavedGraphChangeRefused;
use App\Module\Insights\Service\SavedGraphSettingsInvalid;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes the settings, the name, the width or the legend of a saved graph in one write.
 *
 * @phpstan-import-type GraphUpdatedPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Change a saved graph on the Dashboard, by its id from graph_list. A saved graph is a tile on the Dashboard of the owner, so change one only when the owner asks for it. Pass only what changes: an argument that you leave out keeps its value. name is at most 120 characters and must not be blank. metric, unit, statistic, group, range, bucket, compare and chart are the settings, with the values of graph_save. rows, formula and format change the formula of a formula graph, with the values of graph_save: pass only the one that changes, and rows replaces all the rows. To turn a one-metric graph into a formula graph, pass both rows and formula. A formula graph keeps its first row as metric and statistic, so a bare metric or statistic on a formula graph is refused: pass rows to change the rows, or clearFormula true to go back to one metric, which keeps the first row and takes metric and statistic when you pass them. clearFormula cannot go with rows, formula or format. The settings you pass join the settings the graph keeps, and the whole set must hold together: a setting that the metric or the range cannot take is refused with the values that are valid, also one that the graph kept, such as a bucket that the new range does not take. width is half or full. showLegend is true or false. A call with no argument to change is refused. The call has no confirmation and no Undo, so previous in the response holds the earlier name, settings, formula, width and showLegend, and a call with those values puts them back. When previous.formula is null, the earlier graph read one metric, so pass clearFormula true to put it back. Every change of one call commits together, so a refused call leaves the graph as it was. A removed graph is refused: bring it back with graph_restore first. The response gives the graph as graph, previous, and the Dashboard as graph_list answers it.')]
final readonly class GraphUpdateTool
{
    public const string NAME = 'graph_update';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphFinder $finder,
        private UpdateSavedGraphFieldsHandler $updateGraph,
        private ListSavedGraphsHandler $listGraphs,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string       $graphId      the id of the graph, from graph_list
     * @param ?string      $name         the new name, at most 120 characters
     * @param ?string      $metric       the key of a metric from metric_list
     * @param ?string      $unit         run, card or reading
     * @param ?string      $statistic    median, mean, sum, p90, count or max
     * @param ?string      $group        stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window, wake-gap, wake-cache or none
     * @param ?string      $range        seven-days, thirty-days, ninety-days or all
     * @param ?string      $bucket       day, week or month; seven-days takes day alone
     * @param ?string      $compare      off, or previous
     * @param ?string      $chart        line, bars, stacked-bars, percent-stacked, donut or number
     * @param ?string      $width        half or full
     * @param ?bool        $showLegend   whether the tile shows its legend
     * @param array<mixed> $rows         the lettered rows of a formula, each with letter, metric and statistic; they replace all the rows
     * @param ?string      $formula      the formula over the letters of the rows, such as A / B
     * @param ?string      $format       auto, number, percent, money or duration
     * @param ?bool        $clearFormula true to turn a formula graph back into a graph of one metric
     *
     * @return GraphUpdatedPayload
     */
    public function __invoke(string $graphId, ?string $name = null, ?string $metric = null, ?string $unit = null, ?string $statistic = null, ?string $group = null, ?string $range = null, ?string $bucket = null, ?string $compare = null, ?string $chart = null, ?string $width = null, ?bool $showLegend = null, #[Schema(items: MetricQueryTool::ROW_ITEM, maxItems: MetricFormula::MAX_ROWS)] array $rows = [], ?string $formula = null, ?string $format = null, ?bool $clearFormula = null): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            $passed = array_filter(
                ['metric' => $metric, 'unit' => $unit, 'statistic' => $statistic, 'group' => $group, 'range' => $range, 'bucket' => $bucket, 'compare' => $compare, 'chart' => $chart],
                static fn (?string $value): bool => null !== $value,
            );
            $formulaPassed = array_filter(
                ['rows' => [] === $rows ? null : MetricQueryTool::rowsText($rows), 'formula' => $formula, 'format' => $format],
                static fn (?string $value): bool => null !== $value,
            );
            $clear = true === $clearFormula;
            $settingsPassed = [] !== $passed || [] !== $formulaPassed || $clear;
            if (!$settingsPassed && null === $name && null === $width && null === $showLegend) {
                throw new ToolCallException('Pass at least one argument to change: name, a setting, rows, formula, format, clearFormula, width or showLegend.');
            }
            $newWidth = null === $width ? null : (SavedGraphWidth::tryFrom($width) ?? throw new ToolCallException('width: Use half or full.'));
            if (null !== $name) {
                SavedGraphName::checkNotBlank($name);
            }

            $graph = $this->finder->findActive($project, $graphId);
            $previous = ['name' => $graph->name, 'settings' => GraphListTool::settingsOf($graph), 'formula' => GraphListTool::formulaOf($graph), 'width' => $graph->width->value, 'showLegend' => $graph->showLegend];
            $newName = null !== $name && ($settingsPassed || $name !== $graph->name) ? $name : null;
            $changedWidth = $newWidth !== $graph->width ? $newWidth : null;
            $changedLegend = $showLegend !== $graph->showLegend ? $showLegend : null;
            if ($settingsPassed || null !== $newName || null !== $changedWidth || null !== $changedLegend) {
                try {
                    ($this->updateGraph)(new UpdateSavedGraphFieldsCommand(
                        $project,
                        $graphId,
                        name: $newName,
                        change: $settingsPassed ? new SavedGraphSettingsChange($passed, $formulaPassed, $clear) : null,
                        width: $changedWidth,
                        showLegend: $changedLegend,
                    ));
                } catch (SavedGraphChangeRefused|SavedGraphSettingsInvalid $e) {
                    throw new ToolCallException($e->getMessage(), previous: $e);
                } catch (DomainErrors $e) {
                    throw $this->errorMessages->forAgent($e);
                } catch (NotFoundHttpException) {
                    throw new ToolCallException(SavedGraphFinder::GONE);
                }
            }

            return GraphListTool::answer(($this->listGraphs)(new ListSavedGraphsCommand($project)), $graph) + ['previous' => $previous];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be saved. The error has been logged.', previous: $e);
        }
    }
}
