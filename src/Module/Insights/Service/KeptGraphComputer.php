<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Command\MetricQueryView;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Bridge\Service\BridgeLabels;
use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricChartScale;
use Psr\Clock\ClockInterface;

/** Reads the metrics of a graph and keeps what the drawing needs, with no text in it. */
final readonly class KeptGraphComputer
{
    public function __construct(
        private MetricQueryHandler $metricQuery,
        private BridgeLabels $bridgeLabels,
        private ClockInterface $clock,
    ) {
    }

    public function compute(ShowMetricsCommand $command): KeptGraphResult
    {
        return $this->from($command, $this->fetch($command));
    }

    public function fetch(ShowMetricsCommand $command): MetricQueryView
    {
        $query = $command->query;
        $metricCommand = $query->command($command->project, $command->now);

        // A formula with a problem draws nothing, and the page shows the problem. The handler would refuse it.
        if (null !== $query->formula?->problem) {
            $now = $command->now ?? $this->clock->now();

            return new MetricQueryView($metricCommand, [], new MetricWindow($query->range->startFrom($now), $now));
        }

        // The query takes only what its metric allows, so the handler never refuses it.
        return ($this->metricQuery)($metricCommand);
    }

    public function from(ShowMetricsCommand $command, MetricQueryView $metrics): KeptGraphResult
    {
        $project = $command->project;
        $query = $command->query;
        $groupLabels = MetricGroup::Bridge === $query->group
            ? $this->bridgeLabels->forOwner($project->owner, array_values(array_filter(array_map(static fn (MetricSeries $series): ?string => $series->group, $metrics->series))))
            : [];

        return new KeptGraphResult($command->now ?? $metrics->current->to, $query, MetricChartScale::bySize($metrics->series), $groupLabels, $metrics->current, $metrics->previous);
    }
}
