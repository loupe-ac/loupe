<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Lists the metrics that metric_query reads, with the values each one takes.
 *
 * @phpstan-type MetricListEntry array{label?: string, key: string, units: list<string>, valueType: string, statistics: list<string>, groups: list<string>, description: string}
 */
#[McpTool(name: self::NAME, description: 'List the metrics that metric_query reads. Each metric has key, units (run, card or both), valueType (money in US dollars, tokens, duration, count or ratio), statistics, groups and description. Pass the key as the metric of metric_query, with one of its units, statistics and groups. A built-in duration is in milliseconds. The workflow template of the project may declare more metrics. They have a label, a unit of card alone, and a duration in hours. One bucket-time:<name> entry exists for each bucket that has time on a run of the project.')]
final readonly class MetricListTool
{
    public const string NAME = 'metric_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListMetricsHandler $listMetrics,
    ) {
    }

    /** @return array{metrics: list<MetricListEntry>} */
    public function __invoke(): array
    {
        try {
            $view = ($this->listMetrics)(new ListMetricsCommand($this->subjects->requireReadableProject()));

            return ['metrics' => [
                ...array_map(self::entry(...), $view->metrics),
                ...array_map(static fn (string $name): array => self::entry(new MetricShape(new MetricKey(Metric::BucketTime, $name))), $view->bucketNames),
            ]];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The metrics could not be listed. The error has been logged.', previous: $e);
        }
    }

    /** @return MetricListEntry */
    private static function entry(MetricShape $shape): array
    {
        $entry = [
            'key' => $shape->key->key(),
            'units' => array_map(static fn (MetricUnit $unit): string => $unit->value, $shape->units()),
            'valueType' => $shape->valueType()->value,
            'statistics' => array_map(static fn (MetricStatistic $statistic): string => $statistic->value, $shape->statistics()),
            'groups' => array_map(static fn (MetricGroup $group): string => $group->value, $shape->groups()),
            'description' => $shape->description(),
        ];

        return null === $shape->template ? $entry : ['label' => $shape->template->label, ...$entry];
    }
}
