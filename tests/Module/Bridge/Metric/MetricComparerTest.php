<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricComparer;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSign;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricValueType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MetricComparerTest extends TestCase
{
    private MetricComparer $comparer;

    protected function setUp(): void
    {
        $this->comparer = new MetricComparer();
    }

    public function test_ranges_that_stay_apart_read_clear(): void
    {
        $comparison = $this->median(self::cards([10, 11, 12, 10, 11, 12, 11, 10]), self::cards([20, 21, 22, 20, 21, 22, 21, 20]));

        self::assertSame(MetricSign::Clear, $comparison->sign);
        self::assertSame(MetricChangeKind::Relative, $comparison->changeKind);
        self::assertEqualsWithDelta(-0.4762, (float) $comparison->change, 0.0001);
        self::assertSame(8, $comparison->currentCards);
        self::assertSame(8, $comparison->previousCards);
    }

    public function test_ranges_that_overlap_read_not_clear_yet(): void
    {
        $comparison = $this->median(self::cards([10, 30, 12, 28, 11, 29, 13, 27]), self::cards([11, 29, 12, 28, 13, 27, 10, 30]));

        self::assertSame(MetricSign::NotClearYet, $comparison->sign);
    }

    public function test_the_change_range_holds_zero_exactly_when_the_ranges_overlap(): void
    {
        $clear = $this->median(self::cards([10, 11, 12, 10, 11, 12, 11, 10]), self::cards([20, 21, 22, 20, 21, 22, 21, 20]));
        $overlap = $this->median(self::cards([10, 30, 12, 28, 11, 29, 13, 27]), self::cards([11, 29, 12, 28, 13, 27, 10, 30]));

        self::assertNotNull($clear->changeRange);
        self::assertNotNull($overlap->changeRange);
        self::assertTrue($clear->changeRange->high < 0.0);
        self::assertTrue($overlap->changeRange->low <= 0.0 && $overlap->changeRange->high >= 0.0);
    }

    public function test_four_cards_on_a_period_read_not_enough_cards(): void
    {
        $comparison = $this->median(self::cards([1, 2, 3, 4]), self::cards([10, 11, 12, 13, 14, 15]));

        self::assertSame(MetricSign::NotEnoughCards, $comparison->sign);
        self::assertNotNull($comparison->change);
    }

    public function test_five_cards_of_which_one_has_a_known_value_read_not_enough_cards(): void
    {
        $current = [...self::cards([5]), ...self::unknownCards(4, 10)];

        $comparison = $this->median($current, self::cards([10, 11, 12, 13, 14, 15]));

        self::assertSame(MetricSign::NotEnoughCards, $comparison->sign);
        self::assertSame(1, $comparison->currentCards);
        self::assertSame(5.0, $comparison->currentRange?->point);
    }

    public function test_an_unknown_value_changes_neither_range(): void
    {
        $known = self::cards([10, 11, 12, 10, 11, 12, 11, 10]);
        $previous = self::cards([20, 21, 22, 20, 21, 22, 21, 20]);

        $without = $this->median($known, $previous);
        $with = $this->median([...$known, ...self::unknownCards(6, 100)], $previous);

        self::assertEquals($without->currentRange, $with->currentRange);
        self::assertEquals($without->changeRange, $with->changeRange);
        self::assertSame(8, $with->currentCards);
    }

    public function test_a_period_with_no_known_value_has_no_change_and_reads_not_enough_cards(): void
    {
        $comparison = $this->median([], self::cards([10, 11, 12, 13, 14, 15]));

        self::assertNull($comparison->change);
        self::assertNull($comparison->changeKind);
        self::assertNull($comparison->changeRange);
        self::assertSame(MetricSign::NotEnoughCards, $comparison->sign);
    }

    public function test_a_count_of_an_empty_period_has_no_change(): void
    {
        $emptyBefore = $this->comparer->compare(MetricStatistic::Count, MetricValueType::Money, MetricUnit::Card, self::cards([1, 2]), [], Confidence::forComparisons(1), 'seed');
        $emptyNow = $this->comparer->compare(MetricStatistic::Count, MetricValueType::Money, MetricUnit::Card, self::unknownCards(3, 1), self::cards([1, 2]), Confidence::forComparisons(1), 'seed');

        foreach ([$emptyBefore, $emptyNow] as $comparison) {
            self::assertNull($comparison->change);
            self::assertNull($comparison->changeKind);
        }
        self::assertSame(0, $emptyBefore->previous->value);
    }

    public function test_a_count_of_a_rate_changes_relative_to_the_previous_count(): void
    {
        $comparison = $this->comparer->compare(MetricStatistic::Count, MetricValueType::Ratio, MetricUnit::Run, self::cards([1, 0, 1, 0, 1, 0]), self::cards([1, 0, 1, 0]), Confidence::forComparisons(1), 'seed');

        self::assertSame(MetricChangeKind::Relative, $comparison->changeKind);
        self::assertEqualsWithDelta(0.5, (float) $comparison->change, 1e-9);
    }

    public function test_a_sum_and_a_count_have_a_change_and_no_sign(): void
    {
        foreach ([MetricStatistic::Sum, MetricStatistic::Count] as $statistic) {
            $comparison = $this->comparer->compare($statistic, MetricValueType::Money, MetricUnit::Card, self::cards([1, 2, 3, 4, 5, 6]), self::cards([1, 2, 3]), Confidence::forComparisons(1), 'seed');

            self::assertNull($comparison->sign);
            self::assertNull($comparison->currentRange);
            self::assertNull($comparison->changeRange);
            self::assertNotNull($comparison->change);
        }
    }

    public function test_a_zero_previous_value_gives_an_absolute_change(): void
    {
        $comparison = $this->comparer->compare(MetricStatistic::Sum, MetricValueType::Money, MetricUnit::Card, self::cards([3, 4]), self::cards([0]), Confidence::forComparisons(1), 'seed');

        self::assertSame(7.0, $comparison->change);
        self::assertSame(MetricChangeKind::Absolute, $comparison->changeKind);
    }

    public function test_a_rate_changes_in_points_and_takes_a_wilson_range_per_card(): void
    {
        $current = self::cards([1, 1, 1, 1, 1, 1, 1, 1, 1, 1]);
        $previous = self::cards([0, 0, 0, 0, 0, 0, 0, 0, 0, 1]);

        $comparison = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Ratio, MetricUnit::Card, $current, $previous, Confidence::forComparisons(1), 'seed');

        self::assertSame(MetricChangeKind::Points, $comparison->changeKind);
        self::assertEqualsWithDelta(0.9, (float) $comparison->change, 1e-9);
        self::assertSame(MetricSign::Clear, $comparison->sign);
    }

    public function test_a_maximum_has_a_change_and_no_sign(): void
    {
        $comparison = $this->comparer->compare(MetricStatistic::Max, MetricValueType::Percent, MetricUnit::Run, self::cards([40, 60, 80, 90, 70, 50]), self::cards([10, 20, 30, 40, 50, 60]), Confidence::forComparisons(1), 'seed');

        self::assertSame(30.0, $comparison->change);
        self::assertNull($comparison->sign);
        self::assertNull($comparison->currentRange);
    }

    public function test_a_reading_has_a_change_and_no_sign_whatever_the_statistic(): void
    {
        $rows = static fn (array $values): array => array_map(static fn (int $value): MetricRow => new MetricRow(Uuid::v7(), null, 'desk/work session', new \DateTimeImmutable('2026-10-01'), $value), $values);

        $comparison = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Percent, MetricUnit::Reading, $rows([50, 60, 70]), $rows([10, 20, 30]), Confidence::forComparisons(1), 'seed');

        self::assertSame(40.0, $comparison->change);
        self::assertSame(MetricChangeKind::Absolute, $comparison->changeKind);
        self::assertNull($comparison->sign);
        self::assertNull($comparison->currentRange);
    }

    public function test_a_usage_delta_that_rises_from_a_negative_value_reads_as_a_rise(): void
    {
        $comparison = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Percent, MetricUnit::Run, self::cards([1, 1]), self::cards([-1, -1]), Confidence::forComparisons(1), 'seed');

        self::assertSame(2.0, $comparison->change);
        self::assertSame(MetricChangeKind::Absolute, $comparison->changeKind);
    }

    public function test_a_count_of_a_percent_changes_as_a_count(): void
    {
        $comparison = $this->comparer->compare(MetricStatistic::Count, MetricValueType::Percent, MetricUnit::Run, self::cards([1, 2, 3, 4]), self::cards([1, 2]), Confidence::forComparisons(1), 'seed');

        self::assertSame(1.0, $comparison->change);
        self::assertSame(MetricChangeKind::Relative, $comparison->changeKind);
    }

    public function test_runs_of_one_card_count_as_one_card_and_resample_together(): void
    {
        // Ten runs of two cards. A per-run range would be narrow, and a card resample draws two clusters.
        $runs = static function (array $values): array {
            $rows = [];
            foreach ($values as $card => $perCard) {
                foreach ($perCard as $value) {
                    $rows[] = new MetricRow(Uuid::v7(), $card, null, new \DateTimeImmutable('2026-10-01'), $value);
                }
            }

            return $rows;
        };
        $current = $runs([1 => [10, 10, 10, 10, 10], 2 => [11, 11, 11, 11, 11]]);
        $previous = $runs([3 => [20, 20, 20, 20, 20], 4 => [21, 21, 21, 21, 21]]);

        $comparison = $this->comparer->compare(MetricStatistic::Median, MetricValueType::Duration, MetricUnit::Run, $current, $previous, Confidence::forComparisons(1), 'seed');

        self::assertSame(2, $comparison->currentCards);
        self::assertSame(MetricSign::NotEnoughCards, $comparison->sign);
    }

    public function test_a_run_with_no_card_counts_for_no_card(): void
    {
        $rows = array_map(static fn (int $value): MetricRow => new MetricRow(Uuid::v7(), null, null, new \DateTimeImmutable('2026-10-01'), $value), [1, 2, 3, 4, 5, 6, 7]);

        $comparison = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Duration, MetricUnit::Run, $rows, $rows, Confidence::forComparisons(1), 'seed');

        self::assertSame(0, $comparison->currentCards);
        self::assertSame(MetricSign::NotEnoughCards, $comparison->sign);
    }

    public function test_more_lines_widen_the_range(): void
    {
        $current = self::cards([10, 30, 12, 28, 11, 29, 13, 27]);
        $previous = self::cards([11, 29, 12, 28, 13, 27, 10, 30]);

        $one = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Duration, MetricUnit::Card, $current, $previous, Confidence::forComparisons(1), 'seed');
        $many = $this->comparer->compare(MetricStatistic::Mean, MetricValueType::Duration, MetricUnit::Card, $current, $previous, Confidence::forComparisons(11), 'seed');

        self::assertNotNull($one->currentRange);
        self::assertNotNull($many->currentRange);
        self::assertGreaterThanOrEqual($one->currentRange->high - $one->currentRange->low, $many->currentRange->high - $many->currentRange->low);
    }

    public function test_the_same_seed_gives_the_same_range(): void
    {
        $current = self::cards([10, 30, 12, 28, 11, 29, 13, 27]);
        $previous = self::cards([11, 29, 12, 28, 13, 27, 10, 30]);

        self::assertEquals(
            $this->median($current, $previous),
            $this->median($current, $previous),
        );
    }

    /**
     * @param list<MetricRow> $current
     * @param list<MetricRow> $previous
     */
    private function median(array $current, array $previous): \App\Module\Bridge\Metric\MetricComparison
    {
        return $this->comparer->compare(MetricStatistic::Median, MetricValueType::Duration, MetricUnit::Card, $current, $previous, Confidence::forComparisons(1), 'seed');
    }

    /**
     * One finished card for each value.
     *
     * @param list<int|float> $values
     *
     * @return list<MetricRow>
     */
    private static function cards(array $values): array
    {
        return array_map(static fn (int|float $value, int $index): MetricRow => new MetricRow(Uuid::v7(), $index + 1, null, new \DateTimeImmutable('2026-10-01'), $value), $values, array_keys($values));
    }

    /** @return list<MetricRow> */
    private static function unknownCards(int $count, int $firstNumber): array
    {
        return array_map(static fn (int $index): MetricRow => new MetricRow(Uuid::v7(), $firstNumber + $index, null, new \DateTimeImmutable('2026-10-01'), null), range(0, $count - 1));
    }
}
