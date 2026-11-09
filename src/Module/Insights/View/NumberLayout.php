<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** The statistic over the whole range as a figure for each series, with its change when the graph compares. It has no plot. */
final readonly class NumberLayout
{
    /**
     * @param list<MetricChartSeries>   $series
     * @param list<MetricChartTableRow> $figures in series order
     */
    public function __construct(
        public MetricShape|MetricValueType $metric,
        public ?MetricStatistic $statistic,
        public array $series,
        public array $figures,
        public ChartBox $box = new ChartBox(),
    ) {
    }

    /**
     * Null when there is no series.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(MetricShape|MetricValueType $metric, ?MetricStatistic $statistic, array $series, MetricValueFormatter $formatter, ChartBox $box = new ChartBox()): ?self
    {
        if ([] === $series) {
            return null;
        }
        $chartSeries = MetricChartScale::series($series);

        return new self($metric, $statistic, $chartSeries, MetricChartReadout::tableRows($metric, $statistic, $series, $chartSeries, $formatter, false), $box);
    }

    /** A legend names the series when there is more than one. */
    public function hasLegend(): bool
    {
        return \count($this->series) > 1;
    }
}
