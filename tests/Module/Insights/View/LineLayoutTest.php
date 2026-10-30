<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricSign;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricChartChange;
use App\Module\Insights\View\MetricChartDirection;
use App\Module\Insights\View\MetricChartDot;
use App\Module\Insights\View\MetricChartSeries;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\TestCase;

final class LineLayoutTest extends TestCase
{
    public function test_no_series_gives_no_layout(): void
    {
        self::assertNull(self::build([]));
        self::assertNull(self::build([ChartSeries::of(null, [])]));
    }

    public function test_a_series_is_a_polyline_through_the_centre_of_each_bucket(): void
    {
        $layout = self::build([ChartSeries::of(null, ['2026-09-14' => 1.0, '2026-09-21' => 2.0, '2026-09-28' => 3.0])]);

        self::assertNotNull($layout);
        self::assertCount(1, $layout->lines);
        $line = $layout->lines[0];
        self::assertSame('M209.33 170.67 L500 93.33 L790.67 16', $line->path);
        self::assertSame('', $line->previousPath);
        self::assertSame([209.33, 500.0, 790.67], array_map(static fn (MetricChartDot $dot): float => $dot->x, $line->dots));
        self::assertSame([170.67, 93.33, 16.0], array_map(static fn (MetricChartDot $dot): float => $dot->y, $line->dots));
        self::assertSame([3.0], [$line->dots[2]->point->value]);
        self::assertEquals(new MetricChartSeries(null, 1, 0), $line->series);
        self::assertSame([0.0, 1.0, 2.0, 3.0], array_map(static fn ($tick): float => $tick->value, $layout->yTicks));
        self::assertFalse($layout->comparing);
        self::assertFalse($layout->hasLegend());
    }

    public function test_a_point_with_no_value_breaks_the_line(): void
    {
        $layout = self::build([ChartSeries::of(null, ['2026-09-14' => 1.0, '2026-09-21' => null, '2026-09-28' => 3.0])]);

        self::assertNotNull($layout);
        self::assertSame('M209.33 170.67 M790.67 16', $layout->lines[0]->path);
        self::assertCount(2, $layout->lines[0]->dots);
    }

    public function test_one_bucket_draws_one_dot_in_the_middle_of_the_plot(): void
    {
        $layout = self::build([ChartSeries::of(null, ['2026-09-28' => 2.0])]);

        self::assertNotNull($layout);
        self::assertSame('M500 16', $layout->lines[0]->path);
        self::assertSame([500.0], array_map(static fn (MetricChartDot $dot): float => $dot->x, $layout->lines[0]->dots));
        self::assertCount(1, $layout->hoverTargets);
        self::assertSame(872.0, $layout->hoverTargets[0]->width);
    }

    public function test_the_previous_period_is_a_second_path_on_the_same_axis(): void
    {
        $layout = self::build([ChartSeries::of(
            'plan',
            ['2026-09-21' => 1.0, '2026-09-28' => 2.0],
            ['2026-09-21' => 4.0, '2026-09-28' => 0.5],
        )]);

        self::assertNotNull($layout);
        self::assertTrue($layout->comparing);
        // The previous value 4 tops the axis, so the current line sits lower than it would alone.
        self::assertSame('M282 190 L718 132', $layout->lines[0]->path);
        self::assertSame('M282 16 L718 219', $layout->lines[0]->previousPath);
    }

    public function test_a_previous_bucket_with_no_current_rows_still_has_its_place_on_the_axis(): void
    {
        $layout = self::build([ChartSeries::of('plan', ['2026-09-28' => 2.0], ['2026-09-21' => 1.0, '2026-09-28' => 1.0])]);

        self::assertNotNull($layout);
        self::assertCount(2, $layout->hoverTargets);
        self::assertSame('M718 16', $layout->lines[0]->path);
        self::assertSame('M282 132 L718 132', $layout->lines[0]->previousPath);
    }

    public function test_each_bucket_has_one_hover_target_with_a_row_per_series(): void
    {
        $layout = self::build([
            ChartSeries::of('plan', ['2026-09-21' => 1.0, '2026-09-28' => 2.0], ['2026-09-21' => 4.0, '2026-09-28' => 0.5]),
            ChartSeries::of('review', ['2026-09-21' => 0.25], ['2026-09-21' => 0.5]),
        ]);

        self::assertNotNull($layout);
        self::assertCount(2, $layout->hoverTargets);
        $first = $layout->hoverTargets[0];
        self::assertSame('hover-0', $first->id);
        self::assertSame('2026-09-21', $first->start?->format('Y-m-d'));
        self::assertSame('2026-09-27', $first->end?->format('Y-m-d'));
        self::assertNull($first->series);
        self::assertSame([64.0, 16.0, 436.0, 232.0], [$first->x, $first->y, $first->width, $first->height]);
        self::assertSame(['$1.00', '$0.25'], array_map(static fn ($row): ?string => $row->value, $first->rows));
        self::assertSame(['$4.00', '$0.50'], array_map(static fn ($row): ?string => $row->previous, $first->rows));
        self::assertSame([0, 1], array_map(static fn ($row): int => $row->series->position, $first->rows));
        self::assertNull($first->total);

        $second = $layout->hoverTargets[1];
        self::assertCount(1, $second->rows);
        self::assertSame('$2.00', $second->rows[0]->value);
        self::assertSame('$0.50', $second->rows[0]->previous);
    }

    public function test_a_row_with_neither_value_is_left_out_of_the_hover_card(): void
    {
        $layout = self::build([
            ChartSeries::of('plan', ['2026-09-21' => 1.0, '2026-09-28' => 2.0]),
            ChartSeries::of('review', ['2026-09-21' => null]),
        ]);

        self::assertNotNull($layout);
        self::assertCount(1, $layout->hoverTargets[0]->rows);
    }

    public function test_the_table_carries_the_value_the_previous_value_and_the_change(): void
    {
        $layout = self::build([ChartSeries::of(
            'plan',
            ['2026-09-21' => 1.0],
            ['2026-09-21' => 1.0],
            ChartSeries::comparison(previous: 2.0, change: -0.2, sign: MetricSign::Clear),
            1.5,
        )]);

        self::assertNotNull($layout);
        $row = $layout->table[0];
        self::assertSame('$1.50', $row->value);
        self::assertSame('$2.00', $row->previous);
        self::assertNull($row->share);
        self::assertEquals(new MetricChartChange(MetricChartDirection::Down, '20%', MetricChangeKind::Relative), $row->change);
        self::assertSame(MetricSign::Clear, $row->sign);
        self::assertSame('↓', $row->change?->direction->arrow());
    }

    public function test_more_than_eight_series_share_the_grey_past_the_seventh(): void
    {
        $series = [];
        foreach (range(1, 9) as $index) {
            $series[] = ChartSeries::of('group-'.$index, ['2026-09-28' => 1.0]);
        }

        $layout = self::build($series);

        self::assertNotNull($layout);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 0, 0], array_map(static fn (MetricChartSeries $item): int => $item->slot, $layout->series));
    }

    /** @param list<\App\Module\Bridge\Metric\MetricSeries> $series */
    private static function build(array $series): ?LineLayout
    {
        return LineLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'));
    }
}
