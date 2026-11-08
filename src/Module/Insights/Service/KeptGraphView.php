<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Insights\View\ChartBox;
use App\Module\Insights\View\DashboardGraphResult;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricChartLayouts;
use App\Module\Insights\View\MetricChartRules;
use App\Module\Insights\View\MetricTitle;
use App\Module\Insights\View\MetricValueFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the drawn graph from a kept result, in the language of the viewer. */
final readonly class KeptGraphView
{
    public function __construct(
        private MetricTitle $metricTitle,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(KeptGraphResult $kept, string $locale, ChartBox $box = new ChartBox()): DashboardGraphResult
    {
        $query = $kept->query;
        $lines = \count($kept->series);
        $resolution = MetricChartRules::resolve($query->chart, $query->metric, $query->statistic, $lines);
        $metricName = $this->metricTitle->metricName($query->metric);
        $labels = array_map(
            fn (MetricSeries $series): string => null === $series->group
                ? ($lines > 1 ? $this->translator->trans('analytics.metrics.series.none') : $metricName)
                : ($kept->groupLabels[$series->group] ?? $this->seriesLabel($query->group, $series->group)),
            $kept->series,
        );

        return new DashboardGraphResult(
            $kept->computedAt,
            $this->metricTitle->title($query->metric, $query->unit, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare),
            $resolution,
            MetricChartLayouts::build($resolution->type, $query->metric, $query->statistic, $query->bucket, $kept->series, new MetricValueFormatter($locale), $box),
            $labels,
            array_map(static fn (MetricSeries $series) => $series->groupKey, $kept->series),
            $kept->current,
            $kept->previous,
        );
    }

    private function seriesLabel(MetricGroup $group, string $value): string
    {
        return $group->isWakeGrouping() ? $this->translator->trans('analytics.metrics.series.'.$group->value.'.'.$value) : $value;
    }
}
