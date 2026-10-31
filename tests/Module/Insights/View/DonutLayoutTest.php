<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\DonutLayout;
use App\Module\Insights\View\MetricChartSlice;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;

final class DonutLayoutTest extends TestCase
{
    public function test_no_series_gives_no_layout(): void
    {
        self::assertNull(self::build([]));
    }

    public function test_the_slices_share_a_full_circle_in_proportion_to_their_value(): void
    {
        $layout = self::build([
            ChartSeries::total('plan', 1),
            ChartSeries::total('review', 1),
            ChartSeries::total('merge', 2),
        ]);

        self::assertNotNull($layout);
        self::assertCount(3, $layout->slices);
        self::assertSame([0.0, 90.0, 180.0], array_map(static fn (MetricChartSlice $slice): float => $slice->startAngle, $layout->slices));
        self::assertSame([90.0, 180.0, 360.0], array_map(static fn (MetricChartSlice $slice): float => $slice->endAngle, $layout->slices));
        self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn (MetricChartSlice $slice): float => $slice->share, $layout->slices)), 0.0000001);
        self::assertSame([1, 2, 3], array_map(static fn (MetricChartSlice $slice): int => $slice->series->slot, $layout->slices));
        self::assertSame('4', $layout->total);
    }

    public function test_a_slice_is_an_arc_of_a_ring_that_starts_at_the_top(): void
    {
        $layout = self::build([ChartSeries::total('plan', 1), ChartSeries::total('review', 3)]);

        self::assertNotNull($layout);
        self::assertSame('M140 10A130 130 0 0 1 270 140L220 140A80 80 0 0 0 140 60Z', $layout->slices[0]->path);
        // A slice past a half circle takes the large arc.
        self::assertStringContainsString('A130 130 0 1 1', $layout->slices[1]->path);
    }

    public function test_a_single_slice_is_a_whole_ring(): void
    {
        $layout = self::build([ChartSeries::total('plan', 5), ChartSeries::total('review', 0)]);

        self::assertNotNull($layout);
        self::assertCount(1, $layout->slices);
        self::assertSame(1.0, $layout->slices[0]->share);
        self::assertSame(360.0, $layout->slices[0]->endAngle);
        self::assertSame('M140 10A130 130 0 1 1 140 270A130 130 0 1 1 140 10ZM140 60A80 80 0 1 0 140 220A80 80 0 1 0 140 60Z', $layout->slices[0]->path);
        self::assertCount(1, $layout->hoverTargets);
        self::assertStringNotContainsString('NAN', $layout->slices[0]->path);
    }

    public function test_a_zero_total_shows_no_slices(): void
    {
        $layout = self::build([ChartSeries::total('plan', 0), ChartSeries::total('review', 0)]);

        self::assertNotNull($layout);
        self::assertSame([], $layout->slices);
        self::assertSame([], $layout->hoverTargets);
        self::assertSame('0', $layout->total);
        self::assertSame([null, null], array_map(static fn ($row): ?string => $row->share, $layout->table));
        self::assertSame(['0', '0'], array_map(static fn ($row): ?string => $row->value, $layout->table));
    }

    public function test_a_series_with_no_known_value_has_no_slice(): void
    {
        $layout = self::build([ChartSeries::total('plan', null), ChartSeries::total('review', 2)]);

        self::assertNotNull($layout);
        self::assertCount(1, $layout->slices);
        self::assertSame(1, $layout->slices[0]->series->position);
        self::assertNull($layout->table[0]->value);
    }

    public function test_each_slice_has_a_hover_target_with_its_share_and_value(): void
    {
        $layout = self::build([ChartSeries::total('plan', 1), ChartSeries::total('review', 3)]);

        self::assertNotNull($layout);
        self::assertCount(2, $layout->hoverTargets);
        $target = $layout->hoverTargets[1];
        self::assertSame('hover-1', $target->id);
        self::assertSame($layout->slices[1]->path, $target->path);
        self::assertEquals($layout->slices[1]->series, $target->series);
        self::assertNull($target->start);
        self::assertNull($target->total);
        self::assertCount(1, $target->rows);
        self::assertSame('3', $target->rows[0]->value);
        self::assertSame('75%', $target->rows[0]->share);
    }

    public function test_the_table_lists_every_series_with_its_share(): void
    {
        $layout = self::build([ChartSeries::total('plan', 1), ChartSeries::total('review', 3)]);

        self::assertNotNull($layout);
        self::assertSame(['25%', '75%'], array_map(static fn ($row): ?string => $row->share, $layout->table));
    }

    public function test_more_than_eight_series_share_the_grey_past_the_seventh(): void
    {
        $series = [];
        foreach (range(1, 9) as $index) {
            $series[] = ChartSeries::total('group-'.$index, 1);
        }

        $layout = self::build($series);

        self::assertNotNull($layout);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 0, 0], array_map(static fn (MetricChartSlice $slice): int => $slice->series->slot, $layout->slices));
    }

    /** @param list<MetricSeries> $series */
    private static function build(array $series): ?DonutLayout
    {
        return DonutLayout::build(new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, $series, new MetricValueFormatter('en'));
    }
}
