<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\BarLayout;
use App\Module\Insights\View\ChartBox;
use App\Module\Insights\View\DonutLayout;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricChartLayouts;
use App\Module\Insights\View\MetricChartScale;
use App\Module\Insights\View\MetricChartTick;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricValueFormatter;
use App\Module\Insights\View\NumberLayout;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ChartBoxTest extends TestCase
{
    public function test_the_default_box_holds_the_constants_of_the_scale(): void
    {
        $box = new ChartBox();

        self::assertSame([960, 280, 64, 936, 16, 248], [$box->width, $box->height, $box->plotLeft, $box->plotRight, $box->plotTop, $box->baseline]);
        self::assertSame(232, $box->plotHeight());
        self::assertSame(872, $box->plotWidth());
    }

    public function test_the_preview_box_keeps_the_plot_inside_the_view_box(): void
    {
        $box = ChartBox::preview();

        self::assertSame([1120, 480], [$box->width, $box->height]);
        self::assertGreaterThan(0, $box->plotLeft);
        self::assertLessThan($box->width, $box->plotRight);
        self::assertGreaterThan(0, $box->plotTop);
        self::assertLessThan($box->height, $box->baseline);
    }

    public function test_the_scale_maps_a_value_into_the_given_box(): void
    {
        $box = ChartBox::preview();

        self::assertSame((float) $box->baseline, MetricChartScale::y(0.0, 4.0, 0.0, $box));
        self::assertSame((float) $box->plotTop, MetricChartScale::y(4.0, 4.0, 0.0, $box));
        self::assertSame(248.0, MetricChartScale::y(0.0, 4.0));
        self::assertSame($box->plotHeight(), MetricChartScale::plotHeight($box));
    }

    public function test_a_line_fills_the_preview_plot(): void
    {
        $box = ChartBox::preview();
        $series = [ChartSeries::of(null, ['2026-09-14' => 1.0, '2026-09-21' => 2.0, '2026-09-28' => 3.0])];

        $layout = LineLayout::build(new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, MetricBucket::Week, $series, new MetricValueFormatter('en'), false, new \DateTimeImmutable('2100-01-01 UTC'), $box);

        self::assertNotNull($layout);
        $dots = $layout->lines[0]->dots;
        foreach ($dots as $dot) {
            self::assertGreaterThan($box->plotLeft, $dot->x);
            self::assertLessThan($box->plotRight, $dot->x);
        }
        $topTick = array_last($layout->yTicks);
        self::assertInstanceOf(MetricChartTick::class, $topTick);
        self::assertSame((float) $box->plotTop, $topTick->position);
        self::assertEqualsWithDelta($box->baseline - 0.75 * $box->plotHeight(), $dots[2]->y, 0.01);
        self::assertSame((float) $box->baseline, $layout->yTicks[0]->position);
        self::assertSame((float) $box->plotTop, $layout->hoverTargets[0]->y);
        self::assertSame((float) $box->plotHeight(), $layout->hoverTargets[0]->height);
    }

    public function test_a_stacked_bar_starts_at_the_baseline_of_the_preview_box(): void
    {
        $box = ChartBox::preview();
        $series = [ChartSeries::of(null, ['2026-09-14' => 2.0, '2026-09-21' => 4.0])];

        $layout = BarLayout::build(MetricChartType::StackedBars, new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, MetricBucket::Week, $series, new MetricValueFormatter('en'), true, $box);

        self::assertNotNull($layout);
        $tallest = $layout->buckets[1]->bars[0];
        self::assertEqualsWithDelta($box->plotTop, $tallest->top, 0.01);
        self::assertEqualsWithDelta($box->plotHeight(), $tallest->height, 0.01);
    }

    public function test_the_default_box_changes_nothing(): void
    {
        $series = [ChartSeries::of(null, ['2026-09-14' => 1.0, '2026-09-21' => 2.0])];
        $metric = new MetricShape(new MetricKey(Metric::Runs));
        $formatter = new MetricValueFormatter('en');

        self::assertEquals(
            MetricChartLayouts::build(MetricChartType::Line, $metric, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, new \DateTimeImmutable('2100-01-01 UTC')),
            MetricChartLayouts::build(MetricChartType::Line, $metric, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, new \DateTimeImmutable('2100-01-01 UTC'), new ChartBox()),
        );
    }

    public function test_donut_takes_the_box_and_keeps_the_default_layout(): void
    {
        $series = [ChartSeries::total('a', 3.0), ChartSeries::total('b', 1.0)];
        $metric = new MetricShape(new MetricKey(Metric::Runs));
        $formatter = new MetricValueFormatter('en');
        $donut = static fn (?ChartBox $box): DonutLayout => DonutLayout::build($metric, MetricStatistic::Sum, $series, $formatter, $box) ?? throw new \LogicException('no layout');

        self::assertEquals($donut(null), $donut(new ChartBox()));
        self::assertSame(DonutLayout::SIZE, $donut(null)->size);
        self::assertSame(480, $donut(ChartBox::preview())->size);
        self::assertNotSame($donut(null)->slices[0]->path, $donut(ChartBox::preview())->slices[0]->path);
    }

    public function test_the_layouts_pass_the_box_to_donut_and_number(): void
    {
        $row = new MetricRow(Uuid::v7(), null, null, new \DateTimeImmutable('2026-09-14'), 3);
        $series = [new MetricSeries('a', [], new MetricPoint(null, 3, 1), [$row]), new MetricSeries('b', [], new MetricPoint(null, 1, 1), [$row])];
        $metric = new MetricShape(new MetricKey(Metric::Runs));
        $formatter = new MetricValueFormatter('en');
        $build = static fn (MetricChartType $type): mixed => MetricChartLayouts::build($type, $metric, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, new \DateTimeImmutable('2100-01-01 UTC'), ChartBox::preview());

        $donut = $build(MetricChartType::Donut);
        $number = $build(MetricChartType::Number);

        self::assertInstanceOf(DonutLayout::class, $donut);
        self::assertSame(480, $donut->size);
        self::assertInstanceOf(NumberLayout::class, $number);
        self::assertSame(1120, $number->box->width);
    }
}
