<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** The plot box in view-box units and the value axis that the layouts share. */
final class MetricChartScale
{
    public const int WIDTH = 960;
    public const int HEIGHT = 280;
    public const int PLOT_LEFT = 64;
    public const int PLOT_RIGHT = 936;
    public const int PLOT_TOP = 16;
    public const int BASELINE = 248;
    /** The palette holds eight colours. With more than eight series, the first seven keep theirs and the rest share a grey. */
    public const int PALETTE_SIZE = 8;

    private const int TARGET_TICKS = 4;

    /**
     * The top of the value axis and its step. A count scales as a count. A ratio
     * tops at 100%. Another metric tops at a floor of its own, so tiny values still read.
     *
     * @param list<float> $values
     *
     * @return array{float, float}
     */
    public static function axis(MetricShape $metric, MetricStatistic $statistic, array $values): array
    {
        $type = MetricStatistic::Count === $statistic ? MetricValueType::Count : $metric->valueType();
        if (MetricValueType::Ratio === $type) {
            return [1.0, 0.25];
        }

        $floor = match ($type) {
            MetricValueType::Money => 0.04,
            MetricValueType::Duration => $metric->isInHours() ? 1.0 : 1000.0,
            default => (float) self::TARGET_TICKS,
        };
        $max = max([$floor, ...$values]);
        $step = self::niceStep($max / self::TARGET_TICKS);

        return [ceil($max / $step - 1e-9) * $step, $step];
    }

    /**
     * The share axis of a 100% stack.
     *
     * @return array{float, float}
     */
    public static function shareAxis(): array
    {
        return [1.0, 0.25];
    }

    /** @return list<MetricChartTick> */
    public static function yTicks(float $top, float $step): array
    {
        $ticks = [];
        $tickCount = (int) round($top / $step);
        for ($tick = 0; $tick <= $tickCount; ++$tick) {
            $value = round($tick * $step, 10);
            $ticks[] = new MetricChartTick(self::y($value, $top), $value);
        }

        return $ticks;
    }

    /** The y of a value on an axis that tops at $top. */
    public static function y(float $value, float $top): float
    {
        return round(self::BASELINE - $value / $top * self::plotHeight(), 2);
    }

    public static function plotHeight(): int
    {
        return self::BASELINE - self::PLOT_TOP;
    }

    public static function moneyDecimals(float $step): int
    {
        return $step >= 1 ? 0 : max(2, (int) ceil(-log10($step) - 1e-9));
    }

    /**
     * @param list<MetricSeries> $series
     *
     * @return list<MetricChartSeries>
     */
    public static function series(array $series): array
    {
        $count = \count($series);
        $named = $count > self::PALETTE_SIZE ? self::PALETTE_SIZE - 1 : self::PALETTE_SIZE;
        $list = [];
        foreach ($series as $position => $item) {
            $list[] = new MetricChartSeries($item->group, $position < $named ? $position + 1 : 0, $position);
        }

        return $list;
    }

    /** At most two decimals, with no trailing zero. */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
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
