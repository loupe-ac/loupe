<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;

/**
 * One slice for each series over the whole range, as a ring in a square SVG view box.
 * A slice is the share of the series in the sum of all series, so the values must add up.
 */
final readonly class DonutLayout
{
    public const int SIZE = 280;

    private const float OUTER_RATIO = 130 / 280;
    private const float INNER_RATIO = 80 / 280;

    /**
     * @param list<MetricChartSeries>      $series
     * @param list<MetricChartSlice>       $slices       in series order, with no slice for a series of no value
     * @param list<MetricChartHoverTarget> $hoverTargets one for each slice
     * @param list<MetricChartTableRow>    $table
     */
    public function __construct(
        public MetricShape $metric,
        public MetricStatistic $statistic,
        public array $series,
        public array $slices,
        public array $hoverTargets,
        public array $table,
        /** The sum of all series. Null when no series has a known value. */
        public ?string $total,
        public bool $comparing,
        /** The side of the square view box. */
        public int $size = self::SIZE,
    ) {
    }

    /**
     * Null when there is no series. A sum of zero gives a layout with no slice.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(MetricShape $metric, MetricStatistic $statistic, array $series, MetricValueFormatter $formatter, ?ChartBox $box = null): ?self
    {
        if ([] === $series) {
            return null;
        }

        $size = null === $box ? self::SIZE : $box->height;
        $centre = $size / 2;
        $outer = $size * self::OUTER_RATIO;
        $inner = $size * self::INNER_RATIO;

        $chartSeries = MetricChartScale::series($series);
        $sum = 0;
        $positive = 0;
        $known = false;
        foreach ($series as $item) {
            if (null !== $item->total->value) {
                $known = true;
                $sum += $item->total->value;
                $positive += max(0, $item->total->value);
            }
        }

        $slices = [];
        $targets = [];
        $running = 0;
        if ($positive > 0) {
            foreach ($series as $position => $item) {
                $value = $item->total->value;
                if (null === $value || $value <= 0) {
                    continue;
                }
                $start = round($running / $positive * 360, 4);
                $running += $value;
                $end = round($running / $positive * 360, 4);
                $slice = new MetricChartSlice($chartSeries[$position], $value, $value / $positive, $start, $end, self::path($start, $end, $centre, $outer, $inner));
                $middle = deg2rad(($start + $end) / 2);
                $radius = ($outer + $inner) / 2;
                $targets[] = new MetricChartHoverTarget(
                    'hover-'.\count($slices),
                    null,
                    null,
                    $slice->series,
                    0.0,
                    0.0,
                    0.0,
                    0.0,
                    round($centre + $radius * sin($middle), 2),
                    round($centre - $radius * cos($middle), 2),
                    $slice->path,
                    [new MetricChartHoverRow($slice->series, $formatter->format($value, $metric, $statistic), null, $formatter->share($slice->share))],
                    null,
                );
                $slices[] = $slice;
            }
        }

        return new self(
            metric: $metric,
            statistic: $statistic,
            series: $chartSeries,
            slices: $slices,
            hoverTargets: $targets,
            table: MetricChartReadout::tableRows($metric, $statistic, $series, $chartSeries, $formatter, true),
            total: $known ? $formatter->format($sum, $metric, $statistic) : null,
            comparing: MetricChartReadout::comparing($series),
            size: $size,
        );
    }

    /** A legend names the series when there is more than one. */
    public function hasLegend(): bool
    {
        return \count($this->series) > 1;
    }

    /** A slice of the ring. A slice of the whole circle is two half arcs on each edge, because one arc cannot close on itself. */
    private static function path(float $start, float $end, float $centre, float $outerRadius, float $innerRadius): string
    {
        $outer = MetricChartScale::number($outerRadius);
        $inner = MetricChartScale::number($innerRadius);
        if ($end - $start >= 360 - 1e-6) {
            return \sprintf(
                'M%sA%s %s 0 1 1 %sA%s %s 0 1 1 %sZM%sA%s %s 0 1 0 %sA%s %s 0 1 0 %sZ',
                self::at(0, $outerRadius, $centre),
                $outer,
                $outer,
                self::at(180, $outerRadius, $centre),
                $outer,
                $outer,
                self::at(0, $outerRadius, $centre),
                self::at(0, $innerRadius, $centre),
                $inner,
                $inner,
                self::at(180, $innerRadius, $centre),
                $inner,
                $inner,
                self::at(0, $innerRadius, $centre),
            );
        }

        $large = $end - $start > 180 ? 1 : 0;

        return \sprintf(
            'M%sA%s %s 0 %d 1 %sL%sA%s %s 0 %d 0 %sZ',
            self::at($start, $outerRadius, $centre),
            $outer,
            $outer,
            $large,
            self::at($end, $outerRadius, $centre),
            self::at($end, $innerRadius, $centre),
            $inner,
            $inner,
            $large,
            self::at($start, $innerRadius, $centre),
        );
    }

    /** The point at an angle in degrees clockwise from the top, as "x y". */
    private static function at(float $angle, float $radius, float $centre): string
    {
        $radians = deg2rad($angle);

        return MetricChartScale::number($centre + $radius * sin($radians)).' '.MetricChartScale::number($centre - $radius * cos($radians));
    }
}
