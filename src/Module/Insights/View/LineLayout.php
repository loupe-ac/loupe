<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;

/**
 * The statistic of each bucket as one polyline per series, laid out in the units of an SVG view box.
 * A graph that compares draws the previous period as a second path per series.
 */
final readonly class LineLayout
{
    /**
     * @param list<MetricChartSeries>      $series
     * @param list<MetricChartLine>        $lines        in series order
     * @param list<MetricChartTick>        $yTicks
     * @param list<MetricChartTick>        $xTicks
     * @param list<MetricChartHoverTarget> $hoverTargets one for each bucket
     * @param list<MetricChartTableRow>    $table
     */
    public function __construct(
        public MetricShape $metric,
        public MetricStatistic $statistic,
        public MetricBucket $bucket,
        public array $series,
        public array $lines,
        public array $yTicks,
        /** The decimals of a dollar label on the value axis. */
        public int $moneyDecimals,
        public array $xTicks,
        public bool $longSpan,
        public array $hoverTargets,
        public array $table,
        public bool $comparing,
    ) {
    }

    /**
     * Null when no series has a bucket.
     *
     * @param list<MetricSeries> $series
     * @param bool               $adds   true when the values add up, as MetricChartRules::valuesAdd() says
     */
    public static function build(MetricShape $metric, MetricStatistic $statistic, MetricBucket $bucket, array $series, MetricValueFormatter $formatter, bool $adds): ?self
    {
        $axis = MetricChartTimeAxis::forSeries($bucket, $series, withPrevious: true);
        if (null === $axis) {
            return null;
        }

        $values = [];
        foreach ($series as $item) {
            foreach ([...$item->points, ...$item->previousPoints] as $point) {
                if (null !== $point->value) {
                    $values[] = (float) $point->value;
                }
            }
        }
        [$top, $step] = MetricChartScale::axis($metric, $statistic, $values);

        $chartSeries = MetricChartScale::series($series);
        $lines = [];
        foreach ($series as $position => $item) {
            $dots = [];
            foreach ($item->points as $point) {
                if (null !== $point->start && null !== $point->value) {
                    $dots[] = new MetricChartDot($point, $axis->centre($axis->index($point->start)), MetricChartScale::y($point->value, $top));
                }
            }
            $lines[] = new MetricChartLine($chartSeries[$position], self::path($item->points, $axis, $top), $dots, self::path($item->previousPoints, $axis, $top));
        }

        return new self(
            metric: $metric,
            statistic: $statistic,
            bucket: $bucket,
            series: $chartSeries,
            lines: $lines,
            yTicks: MetricChartScale::yTicks($top, $step),
            moneyDecimals: MetricChartScale::moneyDecimals($step),
            xTicks: $axis->ticks,
            longSpan: $axis->longSpan,
            hoverTargets: MetricChartReadout::bucketTargets($metric, $statistic, $axis, $series, $chartSeries, $formatter, $adds),
            table: MetricChartReadout::tableRows($metric, $statistic, $series, $chartSeries, $formatter, $adds),
            comparing: MetricChartReadout::comparing($series),
        );
    }

    /** A legend names the series when there is more than one. */
    public function hasLegend(): bool
    {
        return \count($this->series) > 1;
    }

    /** @param list<MetricPoint> $points */
    private static function path(array $points, MetricChartTimeAxis $axis, float $top): string
    {
        $parts = [];
        $last = null;
        foreach ($points as $point) {
            if (null === $point->start || null === $point->value) {
                $last = null;

                continue;
            }
            $index = $axis->index($point->start);
            $parts[] = (null !== $last && 1 === $index - $last ? 'L' : 'M').MetricChartScale::number($axis->centre($index)).' '.MetricChartScale::number(MetricChartScale::y($point->value, $top));
            $last = $index;
        }

        return implode(' ', $parts);
    }
}
