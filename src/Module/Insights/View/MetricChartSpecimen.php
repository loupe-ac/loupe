<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricSign;
use App\Module\Bridge\Metric\MetricStatistic;

/** Fixed chart data for the styleguide. It reads no database, so the specimens never change. */
final class MetricChartSpecimen
{
    private const array WEEKS = ['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'];

    /** @var array<string, array{current: list<float>, previous: list<float>, change: float, sign: MetricSign}> */
    private const array LINES = [
        'product-design' => ['current' => [0.15, 0.14, 0.15, 0.16], 'previous' => [0.16, 0.15, 0.15, 0.15], 'change' => -0.01, 'sign' => MetricSign::NotClearYet],
        'tech-design' => ['current' => [0.22, 0.20, 0.21, 0.23], 'previous' => [0.21, 0.22, 0.22, 0.21], 'change' => 0.02, 'sign' => MetricSign::Clear],
        'implementation' => ['current' => [0.72, 0.70, 0.64, 0.68], 'previous' => [0.75, 0.73, 0.73, 0.70], 'change' => -0.16, 'sign' => MetricSign::NotEnoughCards],
    ];

    /** @return array{layout: LineLayout|BarLayout|DonutLayout|NumberLayout, labels: list<string>} */
    public static function of(MetricChartType $type): array
    {
        $shape = new MetricShape(new MetricKey(Metric::Cost));
        $formatter = new MetricValueFormatter('en');
        $labels = array_keys(self::LINES);

        $layout = match ($type) {
            MetricChartType::Line => LineLayout::build($shape, MetricStatistic::Median, MetricBucket::Week, self::series(true), $formatter, false),
            MetricChartType::Bars => BarLayout::build($type, $shape, MetricStatistic::Median, MetricBucket::Week, self::series(false), $formatter, false),
            MetricChartType::StackedBars, MetricChartType::PercentStacked => BarLayout::build($type, $shape, MetricStatistic::Sum, MetricBucket::Week, self::series(false), $formatter, true),
            MetricChartType::Donut => DonutLayout::build($shape, MetricStatistic::Sum, self::series(false), $formatter),
            MetricChartType::Number => NumberLayout::build($shape, MetricStatistic::Median, self::series(true), $formatter),
        };

        return ['layout' => $layout ?? throw new \LogicException('The specimen data always has a bucket.'), 'labels' => $labels];
    }

    /** @return list<MetricSeries> */
    private static function series(bool $comparing): array
    {
        $series = [];
        foreach (self::LINES as $group => $line) {
            $series[] = new MetricSeries(
                $group,
                self::points($line['current']),
                new MetricPoint(null, round(array_sum($line['current']) / 4, 2), 12),
                [],
                $comparing ? self::comparison($line) : null,
                $comparing ? self::points($line['previous']) : [],
            );
        }

        return $series;
    }

    /** @param array{current: list<float>, previous: list<float>, change: float, sign: MetricSign} $line */
    private static function comparison(array $line): MetricComparison
    {
        $current = round(array_sum($line['current']) / 4, 2);
        $previous = round(array_sum($line['previous']) / 4, 2);
        $enough = MetricSign::NotEnoughCards !== $line['sign'];

        return new MetricComparison(
            new MetricPoint(null, $previous, 12),
            $line['change'],
            MetricChangeKind::Relative,
            $enough ? new Interval($current * 0.9, $current * 1.1, $current) : null,
            $enough ? new Interval($previous * 0.9, $previous * 1.1, $previous) : null,
            null,
            $line['sign'],
            $enough ? 12 : 3,
            $enough ? 11 : 4,
        );
    }

    /**
     * @param list<float> $values
     *
     * @return list<MetricPoint>
     */
    private static function points(array $values): array
    {
        $points = [];
        foreach ($values as $index => $value) {
            $points[] = new MetricPoint(new \DateTimeImmutable(self::WEEKS[$index].' 00:00:00 UTC'), $value, 3);
        }

        return $points;
    }
}
