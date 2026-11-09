<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Insights\View\MetricChartScale;
use App\Module\Insights\View\MetricChartTick;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricChartScaleTest extends TestCase
{
    /** @return iterable<string, array{float, float, float}> */
    public static function durations(): iterable
    {
        yield 'about nine minutes' => [540_000.0, 600_000.0, 300_000.0];
        yield 'about 50 minutes' => [3_000_000.0, 3_600_000.0, 900_000.0];
        yield 'about three hours' => [10_000_000.0, 10_800_000.0, 3_600_000.0];
        yield 'under a second' => [700.0, 1_000.0, 500.0];
    }

    #[DataProvider('durations')]
    public function test_a_duration_axis_steps_in_whole_units(float $max, float $top, float $step): void
    {
        self::assertSame([$top, $step, 0.0], MetricChartScale::axis(self::duration(), MetricStatistic::Mean, [$max]));
    }

    public function test_every_duration_tick_is_a_short_label(): void
    {
        $formatter = new MetricValueFormatter('en');
        foreach ([540_000.0, 3_000_000.0, 10_000_000.0, 40_000.0] as $max) {
            [$top, $step] = MetricChartScale::axis(self::duration(), MetricStatistic::Mean, [$max]);
            foreach (MetricChartScale::yTicks($top, $step) as $tick) {
                $label = (string) $formatter->format($tick->value, self::duration(), MetricStatistic::Mean);
                self::assertLessThanOrEqual(6, mb_strlen($label), \sprintf('Tick "%s" of a %s ms axis', $label, $max));
            }
        }
    }

    public function test_a_duration_in_hours_keeps_its_decimal_steps(): void
    {
        self::assertSame([6.0, 2.0, 0.0], MetricChartScale::axis(MetricShape::template(TemplateMetricSets::duration()), MetricStatistic::Mean, [5.24]));
    }

    public function test_the_account_usage_tops_at_one_hundred_and_grows_past_a_higher_value(): void
    {
        $shape = new MetricShape(new MetricKey(Metric::AccountUsage));

        self::assertSame([100.0, 50.0, 0.0], MetricChartScale::axis($shape, MetricStatistic::Max, [40.0]));
        self::assertSame(150.0, MetricChartScale::axis($shape, MetricStatistic::Max, [140.0])[0]);
    }

    public function test_a_run_usage_delta_scales_to_its_points(): void
    {
        foreach ([Metric::SessionUsage, Metric::WeekUsage] as $metric) {
            self::assertSame([4.0, 1.0, 0.0], MetricChartScale::axis(new MetricShape(new MetricKey($metric)), MetricStatistic::Median, [1.0]), $metric->value);
        }
    }

    public function test_a_negative_value_takes_the_axis_below_zero_with_a_tick_on_zero(): void
    {
        [$top, $step, $bottom] = MetricChartScale::axis(new MetricShape(new MetricKey(Metric::SessionUsage)), MetricStatistic::Median, [-1.0, 3.0]);

        self::assertSame([4.0, 2.0, -2.0], [$top, $step, $bottom]);
        self::assertEquals([
            new MetricChartTick(248.0, -2.0),
            new MetricChartTick(170.67, 0.0),
            new MetricChartTick(93.33, 2.0),
            new MetricChartTick(16.0, 4.0),
        ], MetricChartScale::yTicks($top, $step, $bottom));
    }

    public function test_a_formula_result_scales_to_its_data_and_not_to_a_floor(): void
    {
        self::assertSame([0.008, 0.002, 0.0], MetricChartScale::axis(MetricValueType::Fraction, null, [0.0077, 0.003]));
        self::assertSame([40.0, 10.0, 0.0], MetricChartScale::axis(MetricValueType::Number, null, [31.6, 12.0]));
    }

    public function test_a_formula_graph_of_zeros_still_gets_an_axis(): void
    {
        [$top, $step] = MetricChartScale::axis(MetricValueType::Number, null, [0.0, 0.0]);

        self::assertGreaterThan(0.0, $top);
        self::assertGreaterThan(0.0, $step);
    }

    public function test_the_largest_series_take_the_first_slots(): void
    {
        $slots = self::slots([2, 9, 5]);

        self::assertSame([3, 1, 2], $slots);
    }

    public function test_a_negative_total_ranks_by_its_absolute_value(): void
    {
        self::assertSame([3, 1, 2], self::slots([1, -9, 4]));
    }

    public function test_a_series_with_no_total_comes_after_every_known_total(): void
    {
        self::assertSame([3, 1, 2], self::slots([null, 5, 0]));
    }

    public function test_a_tie_keeps_the_query_order(): void
    {
        self::assertSame([1, 2, 3], self::slots([4, 4, 4]));
    }

    public function test_the_smallest_series_past_the_palette_share_the_grey(): void
    {
        $totals = range(1, MetricChartScale::PALETTE_SIZE + 2);
        $slots = self::slots($totals);

        self::assertSame(0, $slots[0]);
        self::assertSame(0, $slots[1]);
        self::assertSame(0, $slots[2]);
        self::assertSame(MetricChartScale::PALETTE_SIZE - 1, $slots[3]);
        self::assertSame(1, $slots[\count($totals) - 1]);
    }

    public function test_every_series_keeps_a_named_slot_when_the_palette_fits_them(): void
    {
        $slots = self::slots(range(1, MetricChartScale::PALETTE_SIZE));

        self::assertNotContains(0, $slots);
    }

    public function test_the_list_keeps_the_order_and_the_position_of_the_input(): void
    {
        $series = [ChartSeries::total('a', 1), ChartSeries::total('b', 9)];
        $list = MetricChartScale::series($series);

        self::assertSame(['a', 'b'], array_map(static fn ($item): ?string => $item->group, $list));
        self::assertSame([0, 1], array_map(static fn ($item): int => $item->position, $list));
    }

    public function test_by_size_orders_the_series_from_the_largest_total(): void
    {
        $series = [ChartSeries::total('a', 1), ChartSeries::total('b', 9), ChartSeries::total('c', 5)];

        self::assertSame(['b', 'c', 'a'], array_map(static fn ($item): ?string => $item->group, MetricChartScale::bySize($series)));
    }

    /**
     * @param list<int|null> $totals
     *
     * @return list<int>
     */
    private static function slots(array $totals): array
    {
        $series = [];
        foreach ($totals as $index => $total) {
            $series[] = ChartSeries::total('g'.$index, $total);
        }

        return array_map(static fn ($item): int => $item->slot, MetricChartScale::series($series));
    }

    private static function duration(): MetricShape
    {
        return new MetricShape(new MetricKey(Metric::Duration));
    }
}
