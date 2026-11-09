<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\Formula\FormulaSeriesBuilder;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Workflow\Contract\TemplateMetricSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FormulaSeriesBuilderTest extends TestCase
{
    private static function row(string $time, int|float|null $value, ?string $group = null, ?Uuid $id = null): MetricRow
    {
        return new MetricRow($id ?? Uuid::v7(), null, $group, new \DateTimeImmutable($time.' UTC'), $value);
    }

    private static function builder(string $rows, string $formula, string $format = 'auto'): FormulaSeriesBuilder
    {
        return new FormulaSeriesBuilder(MetricFormula::parse($rows, $formula, $format, new TemplateMetricSet()));
    }

    public function test_a_bucket_that_only_one_row_holds_has_no_value_unless_that_row_counts(): void
    {
        $builder = self::builder('A:cost:sum,B:wakes:sum', 'A / B');

        $series = $builder->build(MetricGroup::None, MetricBucket::Day, [
            'A' => [self::row('2026-10-01 09:00:00', 4), self::row('2026-10-02 09:00:00', 6)],
            'B' => [self::row('2026-10-01 10:00:00', 2)],
        ], null);

        self::assertEquals([
            new MetricPoint(new \DateTimeImmutable('2026-10-01 00:00:00 UTC'), 2.0, 1),
            new MetricPoint(new \DateTimeImmutable('2026-10-02 00:00:00 UTC'), null, 1),
        ], $series[0]->points);
        self::assertEquals(new MetricPoint(null, 10 / 2, 2), $series[0]->total);
    }

    public function test_a_count_row_reads_zero_in_a_bucket_with_no_row(): void
    {
        $builder = self::builder('A:cost:sum,B:cost:count', 'A * B');

        $series = $builder->build(MetricGroup::None, MetricBucket::Day, [
            'A' => [self::row('2026-10-01 09:00:00', 4), self::row('2026-10-02 09:00:00', 6)],
            'B' => [self::row('2026-10-02 09:00:00', 1)],
        ], null);

        self::assertEquals([
            new MetricPoint(new \DateTimeImmutable('2026-10-01 00:00:00 UTC'), 0, 1),
            new MetricPoint(new \DateTimeImmutable('2026-10-02 00:00:00 UTC'), 6, 1),
        ], $series[0]->points);
    }

    public function test_a_group_that_only_one_row_holds_is_still_a_line(): void
    {
        $builder = self::builder('A:cost:sum,B:wakes:sum', 'A / B');

        $series = $builder->build(MetricGroup::Model, MetricBucket::Day, [
            'A' => [self::row('2026-10-01 09:00:00', 4, 'opus'), self::row('2026-10-01 09:00:00', 3, 'sonnet')],
            'B' => [self::row('2026-10-01 10:00:00', 2, 'opus')],
        ], null);

        self::assertSame(['opus', 'sonnet'], array_map(static fn ($line) => $line->group, $series));
        self::assertEquals(2, $series[0]->total->value);
        self::assertNull($series[1]->total->value);
    }

    public function test_the_rows_of_a_series_hold_each_run_once_with_no_value(): void
    {
        $shared = Uuid::v7();
        $builder = self::builder('A:cost:sum,B:wakes:sum', 'A / B');

        $series = $builder->build(MetricGroup::None, MetricBucket::Day, [
            'A' => [self::row('2026-10-01 09:00:00', 4, id: $shared)],
            'B' => [self::row('2026-10-01 09:00:00', 2, id: $shared), self::row('2026-10-02 09:00:00', 1)],
        ], null);

        self::assertCount(2, $series[0]->rows);
        self::assertSame([null, null], array_map(static fn (MetricRow $row) => $row->value, $series[0]->rows));
        self::assertTrue($series[0]->rows[1]->unitId->equals($shared));
    }

    public function test_a_row_that_the_formula_does_not_use_adds_no_line_and_no_run(): void
    {
        $builder = self::builder('A:cost:sum,B:wakes:sum', 'A * 2');

        $series = $builder->build(MetricGroup::None, MetricBucket::Day, [
            'A' => [self::row('2026-10-01 09:00:00', 4)],
            'B' => [self::row('2026-10-01 09:00:00', 2), self::row('2026-10-02 09:00:00', 1)],
        ], null);

        self::assertCount(1, $series[0]->rows);
        self::assertEquals(8, $series[0]->total->value);
    }

    public function test_no_rows_give_no_series(): void
    {
        self::assertSame([], self::builder('A:cost:sum', 'A')->build(MetricGroup::None, MetricBucket::Day, ['A' => []], null));
    }

    public function test_a_formula_with_a_problem_cannot_build(): void
    {
        $this->expectException(\LogicException::class);

        self::builder('A:cost:sum', 'A / B');
    }
}
