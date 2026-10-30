<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricSign;

/** Metric series for the chart layout tests. */
final class ChartSeries
{
    /**
     * @param array<string, int|float|null> $values   bucket start => value
     * @param array<string, int|float|null> $previous bucket start => value, already moved onto the current dates
     */
    public static function of(?string $group, array $values, array $previous = [], ?MetricComparison $comparison = null, int|float|null $total = null): MetricSeries
    {
        return new MetricSeries(
            $group,
            self::points($values),
            new MetricPoint(null, $total, null === $total ? 0 : 1),
            [],
            $comparison ?? ([] === $previous ? null : self::comparison()),
            self::points($previous),
        );
    }

    /** A series with a total and no time points, which a donut and a number read. */
    public static function total(?string $group, int|float|null $total, ?MetricComparison $comparison = null): MetricSeries
    {
        return new MetricSeries($group, [], new MetricPoint(null, $total, null === $total ? 0 : 2), [], $comparison);
    }

    public static function comparison(
        int|float|null $previous = 2.0,
        ?float $change = -0.2,
        ?MetricChangeKind $kind = MetricChangeKind::Relative,
        ?MetricSign $sign = MetricSign::Clear,
    ): MetricComparison {
        return new MetricComparison(new MetricPoint(null, $previous, 5), $change, $kind, null, null, null, $sign, 6, 7);
    }

    /**
     * @param array<string, int|float|null> $values
     *
     * @return list<MetricPoint>
     */
    private static function points(array $values): array
    {
        $points = [];
        foreach ($values as $start => $value) {
            $points[] = new MetricPoint(new \DateTimeImmutable($start.' 00:00:00 UTC'), $value, null === $value ? 0 : 1);
        }

        return $points;
    }
}
