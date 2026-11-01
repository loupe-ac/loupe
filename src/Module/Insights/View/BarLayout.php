<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/**
 * The statistic of each bucket as bars on a time axis, laid out in the units of an SVG view box.
 * The bars stand side by side, or stack into one column per bucket, or stack to the full height of the plot.
 */
final readonly class BarLayout
{
    private const float MAX_BAR_WIDTH = 48.0;
    /** The part of a bucket that its bars fill. */
    private const float BAR_SHARE = 0.8;
    private const float GAP = 2.0;
    private const float MIN_BAR_HEIGHT = 1.0;
    private const float RADIUS = 4.0;

    /**
     * @param list<MetricChartBucket>      $buckets      oldest first
     * @param list<MetricChartSeries>      $series
     * @param list<MetricChartTick>        $yTicks
     * @param list<MetricChartTick>        $xTicks
     * @param list<MetricChartHoverTarget> $hoverTargets one for each bucket
     * @param list<MetricChartTableRow>    $table
     */
    public function __construct(
        public MetricChartType $type,
        public MetricShape $metric,
        public MetricStatistic $statistic,
        public MetricBucket $bucket,
        public array $buckets,
        public array $series,
        public array $yTicks,
        /** The decimals of a dollar label on the value axis. */
        public int $moneyDecimals,
        public array $xTicks,
        /** True when the time axis spans more than a year, so a label needs its year. */
        public bool $longSpan,
        /** What the value axis counts: the metric, or a share for a 100% stack. */
        public MetricShape|MetricValueType $axisMetric,
        public ?MetricStatistic $axisStatistic,
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
    public static function build(MetricChartType $type, MetricShape $metric, MetricStatistic $statistic, MetricBucket $bucket, array $series, MetricValueFormatter $formatter, bool $adds): ?self
    {
        if (!\in_array($type, [MetricChartType::Bars, MetricChartType::StackedBars, MetricChartType::PercentStacked], true)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a bar chart.', $type->value));
        }
        $axis = MetricChartTimeAxis::forSeries($bucket, $series);
        if (null === $axis) {
            return null;
        }

        $cells = array_fill(0, $axis->count(), []);
        foreach ($series as $position => $item) {
            foreach ($item->points as $point) {
                if (null !== $point->start && null !== $point->value) {
                    $cells[$axis->index($point->start)][$position] = $point;
                }
            }
        }

        $percent = MetricChartType::PercentStacked === $type;
        $stacked = MetricChartType::Bars !== $type;
        if ($percent) {
            [$top, $step, $bottom] = MetricChartScale::shareAxis();
        } else {
            $values = [];
            foreach ($cells as $column) {
                $known = array_map(static fn (MetricPoint $point): float => (float) $point->value, $column);
                $values = [...$values, ...($stacked && [] !== $known ? [array_sum($known)] : $known)];
            }
            [$top, $step, $bottom] = MetricChartScale::axis($metric, $statistic, $values);
        }

        $chartSeries = MetricChartScale::series($series);
        $buckets = [];
        foreach ($cells as $index => $column) {
            $bars = $stacked
                ? self::stack($column, $chartSeries, $axis, $index, $top, $percent)
                : self::beside($column, $chartSeries, $axis, $index, $top, $bottom, \count($series));
            $buckets[] = new MetricChartBucket($axis->starts[$index], $axis->centre($index), $bars);
        }

        return new self(
            type: $type,
            metric: $metric,
            statistic: $statistic,
            bucket: $bucket,
            buckets: $buckets,
            series: $chartSeries,
            yTicks: MetricChartScale::yTicks($top, $step, $bottom),
            moneyDecimals: MetricChartScale::moneyDecimals($step),
            xTicks: $axis->ticks,
            longSpan: $axis->longSpan,
            axisMetric: $percent ? MetricValueType::Ratio : $metric,
            axisStatistic: $percent ? null : $statistic,
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

    /**
     * @param array<int, MetricPoint> $column      the points with a known value, by series position
     * @param list<MetricChartSeries> $chartSeries
     *
     * @return list<MetricChartBar>
     */
    private static function beside(array $column, array $chartSeries, MetricChartTimeAxis $axis, int $index, float $top, float $bottom, int $seriesCount): array
    {
        $plotHeight = MetricChartScale::plotHeight();
        $span = $top - $bottom;
        $zero = MetricChartScale::BASELINE + $bottom / $span * $plotHeight;
        $slotWidth = $axis->bucketWidth * self::BAR_SHARE / $seriesCount;
        $gap = min(self::GAP, $slotWidth / 4);
        $barWidth = min(self::MAX_BAR_WIDTH, $slotWidth - $gap);
        $groupOffset = ($axis->bucketWidth - ($seriesCount * $barWidth + ($seriesCount - 1) * $gap)) / 2;

        $bars = [];
        foreach ($column as $position => $point) {
            $value = (float) $point->value;
            $x = $axis->left($index) + $groupOffset + $position * ($barWidth + $gap);
            $height = 0.0 === $value ? 0.0 : min($plotHeight, max(self::MIN_BAR_HEIGHT, abs($value) / $span * $plotHeight));
            $barTop = $value > 0 ? $zero - $height : $zero;
            $bars[] = new MetricChartBar(
                series: $chartSeries[$position],
                point: $point,
                x: round($x, 2),
                width: round($barWidth, 2),
                top: round($barTop, 2),
                path: match (true) {
                    $value > 0 => self::roundedTop($x, $barTop, $barWidth, $height),
                    $value < 0 => self::roundedBottom($x, $barTop, $barWidth, $height),
                    default => '',
                },
                height: round($height, 2),
            );
        }

        return $bars;
    }

    /**
     * @param array<int, MetricPoint> $column      the points with a known value, by series position
     * @param list<MetricChartSeries> $chartSeries
     *
     * @return list<MetricChartBar>
     */
    private static function stack(array $column, array $chartSeries, MetricChartTimeAxis $axis, int $index, float $top, bool $percent): array
    {
        $values = array_map(static fn (MetricPoint $point): int|float => $point->value ?? 0, $column);
        $total = array_sum($values);
        $barWidth = min(self::MAX_BAR_WIDTH, $axis->bucketWidth * self::BAR_SHARE);
        $x = $axis->left($index) + ($axis->bucketWidth - $barWidth) / 2;

        $heights = [];
        foreach ($values as $position => $value) {
            $heights[$position] = ($percent ? ($total > 0 ? $value / $total : 0.0) : $value / $top) * MetricChartScale::plotHeight();
        }
        $highest = array_key_last(array_filter($heights, static fn (float $height): bool => $height > 0));

        $bars = [];
        $edge = (float) MetricChartScale::BASELINE;
        foreach ($column as $position => $point) {
            $height = $heights[$position];
            $bottom = $edge;
            $edge -= $height;
            $path = '';
            if ($height > 0) {
                $path = $position === $highest ? self::roundedTop($x, $edge, $barWidth, $height) : self::rectangle($x, $edge, $barWidth, $bottom);
            }
            $bars[] = new MetricChartBar(
                series: $chartSeries[$position],
                point: $point,
                x: round($x, 2),
                width: round($barWidth, 2),
                top: round($edge, 2),
                path: $path,
                height: round($height, 2),
                share: $total > 0 ? $values[$position] / $total : null,
            );
        }

        return $bars;
    }

    /** A rectangle with its top corners rounded. The baseline end stays square. */
    private static function roundedTop(float $x, float $top, float $width, float $height): string
    {
        $radius = min(self::RADIUS, $width / 2, $height);
        $right = $x + $width;
        $bottom = $top + $height;
        $number = MetricChartScale::number(...);

        return \sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            $number($x),
            $number($bottom),
            $number($top + $radius),
            $number($x),
            $number($top),
            $number($x + $radius),
            $number($top),
            $number($right - $radius),
            $number($right),
            $number($top),
            $number($right),
            $number($top + $radius),
            $number($bottom),
        );
    }

    /** A rectangle that hangs from the zero line, with its bottom corners rounded. */
    private static function roundedBottom(float $x, float $top, float $width, float $height): string
    {
        $radius = min(self::RADIUS, $width / 2, $height);
        $right = $x + $width;
        $bottom = $top + $height;
        $number = MetricChartScale::number(...);

        return \sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            $number($x),
            $number($top),
            $number($bottom - $radius),
            $number($x),
            $number($bottom),
            $number($x + $radius),
            $number($bottom),
            $number($right - $radius),
            $number($right),
            $number($bottom),
            $number($right),
            $number($bottom - $radius),
            $number($top),
        );
    }

    private static function rectangle(float $x, float $top, float $width, float $bottom): string
    {
        $number = MetricChartScale::number(...);

        return \sprintf('M%s %sV%sH%sV%sZ', $number($x), $number($bottom), $number($top), $number($x + $width), $number($bottom));
    }
}
