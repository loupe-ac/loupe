<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ListSavedGraphsCommand;
use App\Module\Insights\Command\ListSavedGraphsHandler;
use App\Module\Insights\Command\RenameSavedGraphCommand;
use App\Module\Insights\Command\RenameSavedGraphHandler;
use App\Module\Insights\Command\ResizeSavedGraphCommand;
use App\Module\Insights\Command\ResizeSavedGraphHandler;
use App\Module\Insights\Command\SetLegendSavedGraphCommand;
use App\Module\Insights\Command\SetLegendSavedGraphHandler;
use App\Module\Insights\Command\UpdateSavedGraphCommand;
use App\Module\Insights\Command\UpdateSavedGraphHandler;
use App\Module\Insights\Entity\SavedGraphWidth;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes the settings, the name, the width or the legend of a saved graph.
 *
 * @phpstan-import-type GraphUpdatedPayload from GraphListTool
 */
#[McpTool(name: self::NAME, description: 'Change a saved graph on the Dashboard, by its id from graph_list. A saved graph is a tile on the Dashboard of the owner, so change one only when the owner asks for it. Pass only what changes: an argument that you leave out keeps its value. name is at most 120 characters and must not be blank. metric, unit, statistic, group, range, bucket, compare and chart are the settings, with the values of graph_save. The settings you pass join the settings the graph keeps, and the whole set must hold together: a setting that the metric or the range cannot take is refused with the values that are valid, also one that the graph kept, such as a bucket that the new range does not take. width is half or full. showLegend is true or false. A call with no argument to change is refused. The call has no confirmation and no Undo, so previous in the response holds the earlier name, settings, width and showLegend, and a call with those values puts them back. A call that changes several kinds of setting runs several small writes, and a failure between them leaves the first writes in place, so repeat the call to finish it. A removed graph is refused: bring it back with graph_restore first. The response gives the graph as graph, previous, and the Dashboard as graph_list answers it.')]
final readonly class GraphUpdateTool
{
    public const string NAME = 'graph_update';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private SavedGraphFinder $finder,
        private SavedGraphSettingsCheck $settingsCheck,
        private UpdateSavedGraphHandler $updateGraph,
        private RenameSavedGraphHandler $renameGraph,
        private ResizeSavedGraphHandler $resizeGraph,
        private SetLegendSavedGraphHandler $setLegend,
        private ListSavedGraphsHandler $listGraphs,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string  $graphId    the id of the graph, from graph_list
     * @param ?string $name       the new name, at most 120 characters
     * @param ?string $metric     the key of a metric from metric_list
     * @param ?string $unit       run, card or reading
     * @param ?string $statistic  median, mean, sum, p90, count or max
     * @param ?string $group      stage, model, variant, card-type, bridge, harness, account, harness-version, cli-version, skill-version, account-window or none
     * @param ?string $range      seven-days, thirty-days, ninety-days or all
     * @param ?string $bucket     day, week or month; seven-days takes day alone
     * @param ?string $compare    off, or previous
     * @param ?string $chart      line, bars, stacked-bars, percent-stacked, donut or number
     * @param ?string $width      half or full
     * @param ?bool   $showLegend whether the tile shows its legend
     *
     * @return GraphUpdatedPayload
     */
    public function __invoke(string $graphId, ?string $name = null, ?string $metric = null, ?string $unit = null, ?string $statistic = null, ?string $group = null, ?string $range = null, ?string $bucket = null, ?string $compare = null, ?string $chart = null, ?string $width = null, ?bool $showLegend = null): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            $passed = array_filter(
                ['metric' => $metric, 'unit' => $unit, 'statistic' => $statistic, 'group' => $group, 'range' => $range, 'bucket' => $bucket, 'compare' => $compare, 'chart' => $chart],
                static fn (?string $value): bool => null !== $value,
            );
            if ([] === $passed && null === $name && null === $width && null === $showLegend) {
                throw new ToolCallException('Pass at least one argument to change: name, a setting, width or showLegend.');
            }
            $newWidth = null === $width ? null : (SavedGraphWidth::tryFrom($width) ?? throw new ToolCallException('width: Use half or full.'));
            if (null !== $name) {
                SavedGraphName::checkNotBlank($name);
            }

            $graph = $this->finder->findActive($project, $graphId);
            $previous = ['name' => $graph->name, 'settings' => $graph->settings(), 'width' => $graph->width->value, 'showLegend' => $graph->showLegend];
            $merged = $passed + $graph->settings();
            if ([] !== $passed) {
                $this->settingsCheck->check($project, $merged);
            }

            try {
                if ([] !== $passed && $merged !== $previous['settings']) {
                    ($this->updateGraph)(new UpdateSavedGraphCommand($project, $graphId, $name, $merged));
                } elseif (null !== $name && $name !== $graph->name) {
                    ($this->renameGraph)(new RenameSavedGraphCommand($graph, $name));
                }
                if (null !== $newWidth && $newWidth !== $graph->width) {
                    ($this->resizeGraph)(new ResizeSavedGraphCommand($graph, $newWidth));
                }
                if (null !== $showLegend && $showLegend !== $graph->showLegend) {
                    ($this->setLegend)(new SetLegendSavedGraphCommand($graph, $showLegend));
                }
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e);
            } catch (NotFoundHttpException) {
                throw new ToolCallException(SavedGraphFinder::GONE);
            }

            return GraphListTool::answer(($this->listGraphs)(new ListSavedGraphsCommand($project)), $graph) + ['previous' => $previous];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The graph could not be saved. The error has been logged.', previous: $e);
        }
    }
}
