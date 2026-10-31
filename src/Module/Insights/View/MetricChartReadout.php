<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;

/** The numbers a reader sees beside a plot: the table rows and the hover cards. Each text is formatted here. */
final class MetricChartReadout
{
    /** @param list<MetricSeries> $series */
    public static function comparing(array $series): bool
    {
        return array_any($series, fn ($item) => null !== $item->comparison);
    }

    /**
     * @param list<MetricSeries>      $series
     * @param list<MetricChartSeries> $chartSeries
     * @param bool                    $adds        true when the values add up to a whole, so a row has a share
     *
     * @return list<MetricChartTableRow>
     */
    public static function tableRows(MetricShape $metric, MetricStatistic $statistic, array $series, array $chartSeries, MetricValueFormatter $formatter, bool $adds): array
    {
        $whole = 0.0;
        foreach ($series as $item) {
            $whole += $item->total->value ?? 0;
        }

        $rows = [];
        foreach ($series as $position => $item) {
            $value = $item->total->value;
            $comparison = $item->comparison;
            $change = null === $comparison || null === $comparison->change || null === $comparison->changeKind
                ? null
                : new MetricChartChange(
                    $formatter->changeIsNothing($comparison->change, $comparison->changeKind) ? MetricChartDirection::Flat : MetricChartDirection::of($comparison->change),
                    $formatter->change($comparison->change, $comparison->changeKind, $metric, $statistic),
                    $comparison->changeKind,
                );
            $rows[] = new MetricChartTableRow(
                $chartSeries[$position],
                $item->total->rows,
                $formatter->format($value, $metric, $statistic),
                $adds && null !== $value && $whole > 0 ? $formatter->share($value / $whole) : null,
                null === $comparison ? null : $formatter->format($comparison->previous->value, $metric, $statistic),
                $change,
                $comparison?->sign,
                null === $comparison || null === $comparison->sign ? null : new MetricChartSignDetail(
                    self::range($comparison->currentRange, $metric, $statistic, $formatter),
                    self::range($comparison->previousRange, $metric, $statistic, $formatter),
                    $comparison->currentCards,
                    $comparison->previousCards,
                ),
            );
        }

        return $rows;
    }

    private static function range(?Interval $range, MetricShape $metric, MetricStatistic $statistic, MetricValueFormatter $formatter): ?MetricChartRange
    {
        if (null === $range) {
            return null;
        }
        $low = $formatter->format($range->low, $metric, $statistic);
        $high = $formatter->format($range->high, $metric, $statistic);

        return null === $low || null === $high ? null : new MetricChartRange($low, $high);
    }

    /**
     * One hover target for each bucket of the time axis. A series with no known value in a bucket has no row there.
     *
     * @param list<MetricSeries>      $series
     * @param list<MetricChartSeries> $chartSeries
     * @param bool                    $adds        true when the values add up, so the card has a total and each row a share
     *
     * @return list<MetricChartHoverTarget>
     */
    public static function bucketTargets(MetricShape $metric, MetricStatistic $statistic, MetricChartTimeAxis $axis, array $series, array $chartSeries, MetricValueFormatter $formatter, bool $adds): array
    {
        $current = [];
        $previous = [];
        foreach ($series as $position => $item) {
            foreach ($item->points as $point) {
                if (null !== $point->start) {
                    $current[$position][$point->start->getTimestamp()] = $point->value;
                }
            }
            foreach ($item->previousPoints as $point) {
                if (null !== $point->start) {
                    $previous[$position][$point->start->getTimestamp()] = $point->value;
                }
            }
        }

        $targets = [];
        foreach ($axis->starts as $index => $start) {
            $known = [];
            $sum = 0;
            $summed = false;
            foreach ($series as $position => $item) {
                $value = $current[$position][$start->getTimestamp()] ?? null;
                $before = $previous[$position][$start->getTimestamp()] ?? null;
                if (null === $value && null === $before) {
                    continue;
                }
                $known[$position] = [$value, $before];
                if ($adds && null !== $value) {
                    $sum += $value;
                    $summed = true;
                }
            }

            $rows = [];
            foreach ($known as $position => [$value, $before]) {
                $rows[] = new MetricChartHoverRow(
                    $chartSeries[$position],
                    $formatter->format($value, $metric, $statistic),
                    $formatter->format($before, $metric, $statistic),
                    $adds && null !== $value && $sum > 0 ? $formatter->share($value / $sum) : null,
                );
            }

            $targets[] = new MetricChartHoverTarget(
                'hover-'.$index,
                $start,
                $axis->lastDay($index),
                null,
                round($axis->left($index), 2),
                MetricChartScale::PLOT_TOP,
                round($axis->bucketWidth, 2),
                MetricChartScale::plotHeight(),
                $axis->centre($index),
                MetricChartScale::PLOT_TOP,
                '',
                $rows,
                $summed ? $formatter->format($sum, $metric, $statistic) : null,
            );
        }

        return $targets;
    }
}
