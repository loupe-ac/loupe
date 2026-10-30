<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricSign;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\MetricChartDirection;
use App\Module\Insights\View\MetricValueFormatter;
use App\Module\Insights\View\NumberLayout;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;

final class NumberLayoutTest extends TestCase
{
    public function test_no_series_gives_no_layout(): void
    {
        self::assertNull(NumberLayout::build(self::cost(), MetricStatistic::Median, [], new MetricValueFormatter('en')));
    }

    public function test_a_figure_has_its_value_and_no_change_without_a_compare(): void
    {
        $layout = NumberLayout::build(self::cost(), MetricStatistic::Median, [ChartSeries::total(null, 1.5)], new MetricValueFormatter('en'));

        self::assertNotNull($layout);
        self::assertCount(1, $layout->figures);
        self::assertSame('$1.50', $layout->figures[0]->value);
        self::assertNull($layout->figures[0]->change);
        self::assertNull($layout->figures[0]->previous);
        self::assertFalse($layout->hasLegend());
    }

    public function test_a_figure_shows_its_change_and_sign(): void
    {
        $layout = NumberLayout::build(
            self::cost(),
            MetricStatistic::Median,
            [ChartSeries::total('plan', 1.5, ChartSeries::comparison(previous: 1.0, change: 0.5, sign: MetricSign::NotClearYet))],
            new MetricValueFormatter('en'),
        );

        self::assertNotNull($layout);
        $figure = $layout->figures[0];
        self::assertSame('$1.00', $figure->previous);
        self::assertNotNull($figure->change);
        self::assertSame(MetricChartDirection::Up, $figure->change->direction);
        self::assertSame('↑', $figure->change->direction->arrow());
        self::assertSame('50%', $figure->change->amount);
        self::assertSame(MetricSign::NotClearYet, $figure->sign);
    }

    public function test_a_change_of_rates_is_in_points_and_a_zero_change_is_flat(): void
    {
        $formatter = new MetricValueFormatter('en');
        $rate = new MetricShape(new MetricKey(Metric::StopRate));

        $points = NumberLayout::build($rate, MetricStatistic::Mean, [ChartSeries::total(null, 0.25, ChartSeries::comparison(previous: 0.22, change: 0.03, kind: MetricChangeKind::Points))], $formatter);
        $flat = NumberLayout::build(self::cost(), MetricStatistic::Sum, [ChartSeries::total(null, 2.0, ChartSeries::comparison(previous: 2.0, change: 0.0, sign: null))], $formatter);

        self::assertNotNull($points);
        self::assertNotNull($flat);
        self::assertSame('3', $points->figures[0]->change?->amount);
        self::assertSame(MetricChangeKind::Points, $points->figures[0]->change->kind);
        self::assertSame(MetricChartDirection::Flat, $flat->figures[0]->change?->direction);
        self::assertSame('→', $flat->figures[0]->change->direction->arrow());
        self::assertNull($flat->figures[0]->sign);
    }

    public function test_a_change_that_rounds_to_nothing_is_flat(): void
    {
        $formatter = new MetricValueFormatter('en');
        $rate = new MetricShape(new MetricKey(Metric::StopRate));

        $relative = NumberLayout::build(self::cost(), MetricStatistic::Median, [ChartSeries::total(null, 1.0, ChartSeries::comparison(previous: 1.0, change: 0.0004))], $formatter);
        $points = NumberLayout::build($rate, MetricStatistic::Mean, [ChartSeries::total(null, 0.25, ChartSeries::comparison(previous: 0.25, change: -0.0004, kind: MetricChangeKind::Points))], $formatter);
        $small = NumberLayout::build(self::cost(), MetricStatistic::Median, [ChartSeries::total(null, 1.0, ChartSeries::comparison(previous: 1.0, change: 0.004))], $formatter);

        self::assertNotNull($relative);
        self::assertNotNull($points);
        self::assertNotNull($small);
        self::assertSame(MetricChartDirection::Flat, $relative->figures[0]->change?->direction);
        self::assertSame(MetricChartDirection::Flat, $points->figures[0]->change?->direction);
        self::assertSame(MetricChartDirection::Up, $small->figures[0]->change?->direction);
        self::assertSame('0.4%', $small->figures[0]->change->amount);
    }

    public function test_an_absolute_change_prints_in_the_unit_of_the_metric(): void
    {
        $layout = NumberLayout::build(
            self::cost(),
            MetricStatistic::Sum,
            [ChartSeries::total(null, 1.2, ChartSeries::comparison(previous: 0, change: 1.2, kind: MetricChangeKind::Absolute, sign: null))],
            new MetricValueFormatter('en'),
        );

        self::assertNotNull($layout);
        self::assertSame('$1.20', $layout->figures[0]->change?->amount);
        self::assertSame(MetricChartDirection::Up, $layout->figures[0]->change->direction);
    }

    public function test_a_comparison_with_no_change_shows_no_change_figure(): void
    {
        $layout = NumberLayout::build(
            self::cost(),
            MetricStatistic::Median,
            [ChartSeries::total(null, 1.5, ChartSeries::comparison(previous: null, change: null, kind: null, sign: MetricSign::NotEnoughCards))],
            new MetricValueFormatter('en'),
        );

        self::assertNotNull($layout);
        self::assertNull($layout->figures[0]->change);
        self::assertNull($layout->figures[0]->previous);
        self::assertSame(MetricSign::NotEnoughCards, $layout->figures[0]->sign);
    }

    private static function cost(): MetricShape
    {
        return new MetricShape(new MetricKey(Metric::Cost));
    }
}
