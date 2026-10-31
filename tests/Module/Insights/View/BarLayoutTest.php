<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Insights\View\BarLayout;
use App\Module\Insights\View\MetricChartBar;
use App\Module\Insights\View\MetricChartSeries;
use App\Module\Insights\View\MetricChartTick;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;

final class BarLayoutTest extends TestCase
{
    public function test_it_draws_only_the_bar_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::build(MetricChartType::Line, [ChartSeries::of(null, ['2026-09-28' => 1])]);
    }

    public function test_no_series_gives_no_layout(): void
    {
        self::assertNull(self::build(MetricChartType::StackedBars, []));
    }

    public function test_side_by_side_bars_have_a_hover_target_and_no_total(): void
    {
        $layout = self::build(MetricChartType::Bars, [
            ChartSeries::of('plan', ['2026-09-21' => 3, '2026-09-28' => 1]),
            ChartSeries::of('review', ['2026-09-21' => 1]),
        ]);

        self::assertNotNull($layout);
        self::assertCount(2, $layout->hoverTargets);
        $first = $layout->hoverTargets[0];
        self::assertNull($first->total);
        self::assertSame(['3', '1'], array_map(static fn ($row): ?string => $row->value, $first->rows));
        self::assertSame([null, null], array_map(static fn ($row): ?string => $row->share, $first->rows));
        self::assertSame('2026-09-21', $first->start?->format('Y-m-d'));
        self::assertNull($layout->table[0]->share);
    }

    public function test_stacked_bars_pile_the_series_in_one_column(): void
    {
        $layout = self::build(MetricChartType::StackedBars, [
            ChartSeries::of('plan', ['2026-09-28' => 3], total: 3),
            ChartSeries::of('review', ['2026-09-28' => 1], total: 1),
        ]);

        self::assertNotNull($layout);
        self::assertSame([0.0, 1.0, 2.0, 3.0, 4.0], array_map(static fn (MetricChartTick $tick): float => $tick->value, $layout->yTicks));
        $bars = $layout->buckets[0]->bars;
        self::assertSame([476.0, 476.0], array_map(static fn (MetricChartBar $bar): float => $bar->x, $bars));
        self::assertSame([48.0, 48.0], array_map(static fn (MetricChartBar $bar): float => $bar->width, $bars));
        self::assertSame([74.0, 16.0], array_map(static fn (MetricChartBar $bar): float => $bar->top, $bars));
        self::assertSame([174.0, 58.0], array_map(static fn (MetricChartBar $bar): float => $bar->height, $bars));
        // Only the top segment of a column rounds its corners.
        self::assertSame('M476 248V74H524V248Z', $bars[0]->path);
        self::assertSame('M476 74V20Q476 16 480 16H520Q524 16 524 20V74Z', $bars[1]->path);
        self::assertEquals([new MetricChartSeries('plan', 1, 0), new MetricChartSeries('review', 2, 1)], array_map(static fn (MetricChartBar $bar): MetricChartSeries => $bar->series, $bars));
    }

    public function test_the_hover_card_of_a_stack_adds_the_total_and_the_shares(): void
    {
        $layout = self::build(MetricChartType::StackedBars, [
            ChartSeries::of('plan', ['2026-09-28' => 3], total: 3),
            ChartSeries::of('review', ['2026-09-28' => 1], total: 1),
        ]);

        self::assertNotNull($layout);
        $target = $layout->hoverTargets[0];
        self::assertSame('4', $target->total);
        self::assertSame(['3', '1'], array_map(static fn ($row): ?string => $row->value, $target->rows));
        self::assertSame(['75%', '25%'], array_map(static fn ($row): ?string => $row->share, $target->rows));
        self::assertSame(['75%', '25%'], array_map(static fn ($row): ?string => $row->share, $layout->table));
        self::assertSame(['3', '1'], array_map(static fn ($row): ?string => $row->value, $layout->table));
    }

    public function test_a_stack_scales_the_axis_to_the_tallest_column(): void
    {
        $layout = self::build(MetricChartType::StackedBars, [
            ChartSeries::of('plan', ['2026-09-21' => 5, '2026-09-28' => 1]),
            ChartSeries::of('review', ['2026-09-21' => 3, '2026-09-28' => 1]),
        ]);

        self::assertNotNull($layout);
        // The first column holds 8, so the axis tops at 8 and its column fills the plot.
        self::assertSame(8.0, $layout->yTicks[\count($layout->yTicks) - 1]->value);
        $first = $layout->buckets[0]->bars;
        self::assertSame(16.0, $first[1]->top);
        self::assertEqualsWithDelta(232.0, $first[0]->height + $first[1]->height, 0.02);
    }

    public function test_a_hundred_percent_stack_adds_up_to_the_whole_plot_in_every_column(): void
    {
        $layout = self::build(MetricChartType::PercentStacked, [
            ChartSeries::of('plan', ['2026-09-21' => 3, '2026-09-28' => 1, '2026-10-05' => 0]),
            ChartSeries::of('review', ['2026-09-21' => 1, '2026-09-28' => 1, '2026-10-05' => 0]),
            ChartSeries::of('merge', ['2026-09-21' => 4, '2026-09-28' => 2, '2026-10-05' => 0]),
        ]);

        self::assertNotNull($layout);
        self::assertSame([0.0, 0.25, 0.5, 0.75, 1.0], array_map(static fn (MetricChartTick $tick): float => $tick->value, $layout->yTicks));
        self::assertSame(MetricValueType::Ratio, $layout->axisMetric);
        self::assertNull($layout->axisStatistic);
        foreach ([0, 1] as $index) {
            $heights = array_map(static fn (MetricChartBar $bar): float => $bar->height, $layout->buckets[$index]->bars);
            self::assertEqualsWithDelta(232.0, array_sum($heights), 0.02);
            self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn (MetricChartBar $bar): float => $bar->share ?? 0.0, $layout->buckets[$index]->bars)), 0.0001);
        }
        self::assertSame(['37.5%', '12.5%', '50%'], array_map(static fn ($row): ?string => $row->share, $layout->hoverTargets[0]->rows));
    }

    public function test_a_column_that_adds_up_to_zero_draws_nothing_and_no_nan(): void
    {
        $layout = self::build(MetricChartType::PercentStacked, [
            ChartSeries::of('plan', ['2026-09-28' => 0]),
            ChartSeries::of('review', ['2026-09-28' => 0]),
        ]);

        self::assertNotNull($layout);
        foreach ($layout->buckets[0]->bars as $bar) {
            self::assertSame('', $bar->path);
            self::assertSame(0.0, $bar->height);
            self::assertSame(248.0, $bar->top);
        }
        self::assertSame(['0', '0'], array_map(static fn ($row): ?string => $row->value, $layout->hoverTargets[0]->rows));
        self::assertSame([null, null], array_map(static fn ($row): ?string => $row->share, $layout->hoverTargets[0]->rows));
        self::assertSame([0.0, 0.25, 0.5, 0.75, 1.0], array_map(static fn (MetricChartTick $tick): float => $tick->value, $layout->yTicks));
    }

    public function test_a_stack_skips_a_series_with_no_value(): void
    {
        $layout = self::build(MetricChartType::StackedBars, [
            ChartSeries::of('plan', ['2026-09-21' => 2, '2026-09-28' => null]),
            ChartSeries::of('review', ['2026-09-21' => 2, '2026-09-28' => 2]),
        ]);

        self::assertNotNull($layout);
        self::assertCount(1, $layout->buckets[1]->bars);
        self::assertSame('2', $layout->hoverTargets[1]->total);
    }

    public function test_more_than_eight_series_stack_with_the_grey_past_the_seventh(): void
    {
        $series = [];
        foreach (range(1, 9) as $index) {
            $series[] = ChartSeries::of('group-'.$index, ['2026-09-28' => 1]);
        }

        $layout = self::build(MetricChartType::StackedBars, $series);

        self::assertNotNull($layout);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 0, 0], array_map(static fn (MetricChartBar $bar): int => $bar->series->slot, $layout->buckets[0]->bars));
        self::assertSame('9', $layout->hoverTargets[0]->total);
    }

    public function test_side_by_side_bars_of_a_sum_show_shares_and_totals_when_the_values_add_up(): void
    {
        $series = [
            ChartSeries::of('a', ['2026-09-28' => 3], total: 3),
            ChartSeries::of('b', ['2026-09-28' => 1], total: 1),
            ChartSeries::of('c', ['2026-09-28' => 4], total: 4),
        ];

        $adding = self::build(MetricChartType::Bars, $series, true);
        $plain = self::build(MetricChartType::Bars, $series, false);

        self::assertNotNull($adding);
        self::assertNotNull($plain);
        self::assertSame(['37.5%', '12.5%', '50%'], array_map(static fn ($row): ?string => $row->share, $adding->table));
        self::assertSame('8', $adding->hoverTargets[0]->total);
        self::assertSame([null, null, null], array_map(static fn ($row): ?string => $row->share, $plain->table));
        self::assertNull($plain->hoverTargets[0]->total);
    }

    public function test_a_series_with_no_known_value_still_draws_an_axis(): void
    {
        foreach ([MetricChartType::Bars, MetricChartType::StackedBars] as $type) {
            $layout = self::build($type, [ChartSeries::of(null, ['2026-09-28' => null])]);

            self::assertNotNull($layout);
            self::assertSame([], $layout->buckets[0]->bars);
            self::assertNotSame([], $layout->yTicks);
        }
    }

    public function test_a_week_with_no_rows_keeps_an_empty_column(): void
    {
        $layout = self::build(MetricChartType::Bars, [ChartSeries::of(null, ['2026-09-14' => 1, '2026-09-28' => 2])]);

        self::assertNotNull($layout);
        self::assertSame(['2026-09-14', '2026-09-21', '2026-09-28'], array_map(static fn ($bucket): string => $bucket->start->format('Y-m-d'), $layout->buckets));
        self::assertSame([], $layout->buckets[1]->bars);
    }

    /** @param list<MetricSeries> $series */
    private static function build(MetricChartType $type, array $series, ?bool $adds = null): ?BarLayout
    {
        return BarLayout::build($type, new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, MetricBucket::Week, $series, new MetricValueFormatter('en'), $adds ?? MetricChartType::Bars !== $type);
    }
}
