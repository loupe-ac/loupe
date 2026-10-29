<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Service\BridgeLabels;

final readonly class ShowMetricsHandler
{
    public function __construct(
        private MetricQueryHandler $metricQuery,
        private BridgeLabels $bridgeLabels,
    ) {
    }

    public function __invoke(ShowMetricsCommand $command): ShowMetricsView
    {
        $project = $command->project;
        $query = $command->query;
        // The query takes only what its metric allows, so the handler never refuses it.
        $metrics = ($this->metricQuery)(new MetricQueryCommand($project, $query->unit, $query->metric, $query->statistic, $query->group, $query->range, $query->bucket));
        $groupLabels = MetricGroup::Bridge === $query->group
            ? $this->bridgeLabels->forOwner($project->owner, array_values(array_filter(array_map(static fn (MetricSeries $series): ?string => $series->group, $metrics->series))))
            : [];

        return new ShowMetricsView($project, $metrics, $groupLabels);
    }
}
