<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\BarLayout;
use App\Module\Insights\View\ChartBox;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricChartLayouts;
use App\Module\Insights\View\MetricChartScale;
use App\Module\Insights\View\MetricChartTick;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;

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

        $layout = LineLayout::build(new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, MetricBucket::Week, $series, new MetricValueFormatter('en'), false, $box);

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
            MetricChartLayouts::build(MetricChartType::Line, $metric, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter),
            MetricChartLayouts::build(MetricChartType::Line, $metric, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, new ChartBox()),
        );
    }
}
