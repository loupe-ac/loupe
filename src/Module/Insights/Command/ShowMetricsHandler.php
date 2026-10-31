<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Service\BridgeLabels;
use App\Module\Insights\View\MetricChartLayouts;
use App\Module\Insights\View\MetricChartRules;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricTitle;
use App\Module\Insights\View\MetricValueFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ShowMetricsHandler
{
    public function __construct(
        private MetricQueryHandler $metricQuery,
        private BridgeLabels $bridgeLabels,
        private MetricTitle $metricTitle,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ShowMetricsCommand $command): ShowMetricsView
    {
        $project = $command->project;
        $query = $command->query;
        // The query takes only what its metric allows, so the handler never refuses it.
        $metrics = ($this->metricQuery)(new MetricQueryCommand($project, $query->unit, $query->metric, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare, $command->now));
        $groupLabels = MetricGroup::Bridge === $query->group
            ? $this->bridgeLabels->forOwner($project->owner, array_values(array_filter(array_map(static fn (MetricSeries $series): ?string => $series->group, $metrics->series))))
            : [];

        $lines = \count($metrics->series);
        $resolution = MetricChartRules::resolve($query->chart, $query->metric, $query->statistic, $lines);
        $locks = [];
        foreach (MetricChartType::cases() as $type) {
            $locks[$type->value] = MetricChartRules::lock($type, $query->metric, $query->statistic, $lines);
        }

        $metricName = $this->metricTitle->metricName($query->metric);
        $labels = array_map(
            fn (MetricSeries $series): string => null === $series->group
                ? ($lines > 1 ? $this->translator->trans('analytics.metrics.series.none') : $metricName)
                : ($groupLabels[$series->group] ?? $series->group),
            $metrics->series,
        );

        return new ShowMetricsView(
            $project,
            $metrics,
            $this->metricTitle->title($query->metric, $query->unit, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare),
            $resolution,
            $locks,
            MetricChartLayouts::build($resolution->type, $query->metric, $query->statistic, $query->bucket, $metrics->series, new MetricValueFormatter(\Locale::getDefault())),
            $labels,
        );
    }
}
