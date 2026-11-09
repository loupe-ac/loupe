<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** The value axis that the layouts share, and the constants of the default plot box in view-box units. */
final class MetricChartScale
{
    public const int WIDTH = 960;
    public const int HEIGHT = 280;
    public const int PLOT_LEFT = 64;
    public const int PLOT_RIGHT = 936;
    public const int PLOT_TOP = 16;
    public const int BASELINE = 248;
    /** The palette holds twelve colours. With more series than colours, the largest keep a colour and the rest share a grey. */
    public const int PALETTE_SIZE = 12;

    private const int TARGET_TICKS = 4;

    /** Axis steps of a duration in milliseconds that read as whole units. */
    private const array DURATION_STEPS = [
        1, 2, 5, 10, 20, 50, 100, 200, 500,
        1_000, 2_000, 5_000, 10_000, 15_000, 30_000,
        60_000, 120_000, 300_000, 600_000, 900_000, 1_800_000,
        3_600_000, 7_200_000, 10_800_000, 21_600_000, 43_200_000, 86_400_000,
    ];

    /**
     * The top of the value axis, its step and its bottom. A count scales as a count. A ratio
     * tops at 100%. The account usage tops at 100 or above. Another metric tops at a floor of its own, so tiny values still read.
     * The axis starts at zero, or below zero when a value is negative, such as a run usage delta that fell.
     *
     * @param list<float> $values
     *
     * @return array{float, float, float}
     */
    public static function axis(MetricShape|MetricValueType $metric, ?MetricStatistic $statistic, array $values): array
    {
        $shape = $metric instanceof MetricShape ? $metric : null;
        $type = MetricStatistic::Count === $statistic ? MetricValueType::Count : ($shape?->valueType() ?? $metric);
        if (MetricValueType::Ratio === $type) {
            return [1.0, 0.25, 0.0];
        }

        $inHours = $shape?->isInHours() ?? false;
        $positive = max([0.0, ...$values]);
        $floor = match (true) {
            MetricValueType::Percent === $type && Metric::AccountUsage === $shape?->key->metric => 100.0,
            MetricValueType::Money === $type => 0.04,
            MetricValueType::Duration === $type => $inHours ? 1.0 : 1000.0,
            // A formula result can be far below 1, so the axis follows the data and the floor only serves a graph of zeros.
            MetricValueType::Number === $type || MetricValueType::Fraction === $type => $positive > 0.0 ? $positive : 1.0,
            default => (float) self::TARGET_TICKS,
        };
        $max = max([$floor, ...$values]);
        $min = min([0.0, ...$values]);
        $raw = ($max - $min) / self::TARGET_TICKS;
        $step = MetricValueType::Duration === $type && !$inHours ? self::durationStep($raw) : self::niceStep($raw);

        return [ceil($max / $step - 1e-9) * $step, $step, $min < 0 ? floor($min / $step + 1e-9) * $step : 0.0];
    }

    /**
     * The share axis of a 100% stack.
     *
     * @return array{float, float, float}
     */
    public static function shareAxis(): array
    {
        return [1.0, 0.25, 0.0];
    }

    /** @return list<MetricChartTick> */
    public static function yTicks(float $top, float $step, float $bottom = 0.0, ChartBox $box = new ChartBox()): array
    {
        $ticks = [];
        $tickCount = (int) round(($top - $bottom) / $step);
        for ($tick = 0; $tick <= $tickCount; ++$tick) {
            $value = round($bottom + $tick * $step, 10);
            $ticks[] = new MetricChartTick(self::y($value, $top, $bottom, $box), $value);
        }

        return $ticks;
    }

    /** The y of a value on an axis that runs from $bottom to $top. */
    public static function y(float $value, float $top, float $bottom = 0.0, ChartBox $box = new ChartBox()): float
    {
        return round($box->baseline - ($value - $bottom) / ($top - $bottom) * $box->plotHeight(), 2);
    }

    public static function plotHeight(ChartBox $box = new ChartBox()): int
    {
        return $box->plotHeight();
    }

    public static function moneyDecimals(float $step): int
    {
        return $step >= 1 ? 0 : max(2, (int) ceil(-log10($step) - 1e-9));
    }

    /**
     * The series from the largest total to the smallest. A series with no known total comes last, and a tie keeps its place.
     *
     * @param list<MetricSeries> $series
     *
     * @return list<MetricSeries>
     */
    public static function bySize(array $series): array
    {
        return array_map(static fn (int $position): MetricSeries => $series[$position], self::order($series));
    }

    /**
     * Gives the named slots by the size of the total. The list keeps the order of the input.
     *
     * @param list<MetricSeries> $series
     *
     * @return list<MetricChartSeries>
     */
    public static function series(array $series): array
    {
        $named = \count($series) > self::PALETTE_SIZE ? self::PALETTE_SIZE - 1 : self::PALETTE_SIZE;
        $list = [];
        $ranks = array_flip(self::order($series));
        ksort($ranks);
        foreach ($ranks as $position => $rank) {
            $list[] = new MetricChartSeries($series[$position]->group, $rank < $named ? $rank + 1 : 0, $position);
        }

        return $list;
    }

    /**
     * The positions of the series, from the largest absolute total to the smallest.
     *
     * @param list<MetricSeries> $series
     *
     * @return list<int>
     */
    private static function order(array $series): array
    {
        $order = array_keys($series);
        usort($order, static function (int $a, int $b) use ($series): int {
            $left = $series[$a]->total->value;
            $right = $series[$b]->total->value;

            return match (true) {
                null === $left && null === $right => $a <=> $b,
                null === $left => 1,
                null === $right => -1,
                default => abs($right) <=> abs($left) ?: $a <=> $b,
            };
        });

        return $order;
    }

    /** At most two decimals, with no trailing zero. */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function durationStep(float $raw): float
    {
        foreach (self::DURATION_STEPS as $step) {
            if ($step >= $raw - 1e-9) {
                return (float) $step;
            }
        }

        return self::niceStep($raw / 86_400_000) * 86_400_000;
    }

    /** A step of 1, 2 or 5 times a power of ten. */
    private static function niceStep(float $raw): float
    {
        $magnitude = 10 ** floor(log10($raw));
        $fraction = $raw / $magnitude;
        $nice = match (true) {
            $fraction <= 1 + 1e-9 => 1,
            $fraction <= 2 + 1e-9 => 2,
            $fraction <= 5 + 1e-9 => 5,
            default => 10,
        };

        return round($nice * $magnitude, 10);
    }
}
