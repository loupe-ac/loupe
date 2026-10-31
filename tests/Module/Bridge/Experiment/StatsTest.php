<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\MetricStatistic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class StatsTest extends TestCase
{
    public function test_wilson_gives_the_known_range_for_eight_of_ten(): void
    {
        $interval = Stats::wilson(8, 10, Confidence::forComparisons(1));

        self::assertNotNull($interval);
        self::assertEqualsWithDelta(0.4902, $interval->low, 0.0001);
        self::assertEqualsWithDelta(0.9433, $interval->high, 0.0001);
        self::assertEqualsWithDelta(0.8, $interval->point, 1e-12);
    }

    public function test_wilson_starts_at_zero_for_no_successes(): void
    {
        $interval = Stats::wilson(0, 10, Confidence::forComparisons(1));

        self::assertNotNull($interval);
        self::assertSame(0.0, $interval->low);
        self::assertEqualsWithDelta(0.2775, $interval->high, 0.0001);
        self::assertSame(0.0, $interval->point);
    }

    public function test_wilson_ends_at_one_for_all_successes(): void
    {
        $interval = Stats::wilson(10, 10, Confidence::forComparisons(1));

        self::assertNotNull($interval);
        self::assertEqualsWithDelta(0.7225, $interval->low, 0.0001);
        self::assertSame(1.0, $interval->high);
    }

    public function test_wilson_has_no_range_for_no_trials(): void
    {
        self::assertNull(Stats::wilson(0, 0, Confidence::forComparisons(1)));
    }

    public function test_bootstrap_gives_the_same_range_for_the_same_seed(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        self::assertEquals(Stats::bootstrapMean($values, 'seed-a', Confidence::forComparisons(1)), Stats::bootstrapMean($values, 'seed-a', Confidence::forComparisons(1)));
    }

    public function test_bootstrap_gives_another_range_for_another_seed(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        self::assertNotEquals(Stats::bootstrapMean($values, 'seed-a', Confidence::forComparisons(1)), Stats::bootstrapMean($values, 'seed-b', Confidence::forComparisons(1)));
    }

    public function test_bootstrap_range_contains_the_mean(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        $interval = Stats::bootstrapMean($values, 'seed-a', Confidence::forComparisons(1));

        self::assertNotNull($interval);
        self::assertSame(5.5, $interval->point);
        self::assertLessThan(5.5, $interval->low);
        self::assertGreaterThan(5.5, $interval->high);
        self::assertGreaterThanOrEqual(1.0, $interval->low);
        self::assertLessThanOrEqual(10.0, $interval->high);
    }

    public function test_bootstrap_of_one_value_is_that_value(): void
    {
        self::assertEquals(new Interval(4.0, 4.0, 4.0), Stats::bootstrapMean([4], 'seed-a', Confidence::forComparisons(1)));
    }

    public function test_bootstrap_refuses_no_resamples(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Stats::bootstrapMean([1, 2], 'seed-a', Confidence::forComparisons(1), 0);
    }

    public function test_bootstrap_has_no_range_for_no_values(): void
    {
        self::assertNull(Stats::bootstrapMean([], 'seed-a', Confidence::forComparisons(1)));
    }

    /** @return iterable<string, array{MetricStatistic}> */
    public static function clusterStatistics(): iterable
    {
        yield 'median' => [MetricStatistic::Median];
        yield 'p90' => [MetricStatistic::P90];
        yield 'mean' => [MetricStatistic::Mean];
    }

    #[DataProvider('clusterStatistics')]
    public function test_the_cluster_walk_gives_the_statistic_of_the_joined_values_of_each_draw(MetricStatistic $statistic): void
    {
        $clusters = [[5, 1, 9], [2], [7, 7, 3, 12], [4, 6], [8, 8.5, 0.5, 11, 10]];
        $draws = Stats::clusterDraws($clusters, $statistic, 'seed-w', 60);

        // Replay the same draws and join the values of the picked clusters.
        $randomizer = new Randomizer(new Mt19937(crc32('seed-w')));
        $replayed = [];
        for ($i = 0; $i < 60; ++$i) {
            $joined = [];
            for ($j = 0; $j < \count($clusters); ++$j) {
                $joined = [...$joined, ...$clusters[$randomizer->getInt(0, \count($clusters) - 1)]];
            }
            $replayed[] = $statistic->of($joined);
        }

        self::assertCount(60, $draws);
        foreach ($draws as $i => $draw) {
            self::assertEqualsWithDelta($replayed[$i], $draw, 1e-9);
        }
    }

    public function test_the_cluster_draw_of_a_rate_is_the_share_of_non_zero_values(): void
    {
        $clusters = [[1, 0, 1], [0], [1, 1], [0, 0, 0]];
        $draws = Stats::clusterDraws($clusters, null, 'seed-r', 40);

        $randomizer = new Randomizer(new Mt19937(crc32('seed-r')));
        foreach ($draws as $draw) {
            $joined = [];
            for ($j = 0; $j < \count($clusters); ++$j) {
                $joined = [...$joined, ...$clusters[$randomizer->getInt(0, \count($clusters) - 1)]];
            }
            self::assertEqualsWithDelta(array_sum($joined) / \count($joined), $draw, 1e-9);
        }
    }

    public function test_a_cluster_bootstrap_range_holds_the_statistic_and_follows_the_seed(): void
    {
        $clusters = [[5, 1, 9], [2], [7, 7, 3, 12], [4, 6], [8, 8.5, 0.5, 11, 10], [3, 3]];
        $confidence = Confidence::forComparisons(1);

        $interval = Stats::clusterBootstrap($clusters, MetricStatistic::Median, 'seed-a', $confidence);

        self::assertNotNull($interval);
        self::assertSame(MetricStatistic::Median->of(array_merge(...$clusters)), $interval->point);
        self::assertLessThanOrEqual($interval->point, $interval->low);
        self::assertGreaterThanOrEqual($interval->point, $interval->high);
        self::assertEquals($interval, Stats::clusterBootstrap($clusters, MetricStatistic::Median, 'seed-a', $confidence));
        self::assertNotEquals(Stats::clusterDraws($clusters, MetricStatistic::Median, 'seed-a'), Stats::clusterDraws($clusters, MetricStatistic::Median, 'seed-b'));
    }

    public function test_a_few_clusters_of_many_runs_give_a_wider_range_than_one_cluster_per_run(): void
    {
        $confidence = Confidence::forComparisons(1);
        $perCard = [[1, 1, 1, 1, 1, 1], [9, 9, 9, 9, 9, 9], [5, 5, 5, 5, 5, 5]];
        $perRun = array_map(static fn (int $value): array => [$value], array_merge(...$perCard));

        $cards = Stats::clusterBootstrap($perCard, MetricStatistic::Mean, 'seed-a', $confidence);
        $runs = Stats::clusterBootstrap($perRun, MetricStatistic::Mean, 'seed-a', $confidence);

        self::assertNotNull($cards);
        self::assertNotNull($runs);
        self::assertGreaterThan($runs->high - $runs->low, $cards->high - $cards->low);
    }

    public function test_a_cluster_bootstrap_has_no_range_for_no_cluster(): void
    {
        self::assertNull(Stats::clusterBootstrap([], MetricStatistic::Mean, 'seed-a', Confidence::forComparisons(1)));
        self::assertNull(Stats::clusterBootstrap([[]], MetricStatistic::Mean, 'seed-a', Confidence::forComparisons(1)));
    }

    public function test_a_cluster_bootstrap_has_no_range_for_a_sum_and_a_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Stats::clusterBootstrap([[1]], MetricStatistic::Sum, 'seed-a', Confidence::forComparisons(1));
    }

    /**
     * @return iterable<string, array{Interval, Interval, int, int, bool}>
     */
    public static function clearCases(): iterable
    {
        $low = new Interval(0.1, 0.3, 0.2);
        $high = new Interval(0.6, 0.9, 0.75);
        $wide = new Interval(0.1, 0.9, 0.5);

        yield 'apart ranges' => [$low, $high, 5, 5, true];
        yield 'too few finished in a' => [$low, $high, 4, 5, false];
        yield 'too few finished in b' => [$low, $high, 5, 4, false];
        yield 'overlapping ranges, far points' => [$wide, $high, 5, 5, false];
        yield 'overlapping ranges, points within 10%' => [$wide, new Interval(0.2, 0.8, 0.46), 5, 5, true];
        yield 'overlapping ranges, points just past 10%' => [$wide, new Interval(0.2, 0.8, 0.449), 5, 5, false];
        yield 'overlapping ranges, both points zero' => [new Interval(0.0, 0.3, 0.0), new Interval(0.0, 0.4, 0.0), 5, 5, true];
        yield 'negative points within 10%' => [new Interval(-9.0, 1.0, -10.0), new Interval(-8.0, 0.0, -9.5), 5, 5, true];
        yield 'touching ranges overlap' => [new Interval(0.1, 0.5, 0.3), new Interval(0.5, 0.9, 0.7), 5, 5, false];
    }

    #[DataProvider('clearCases')]
    public function test_is_clear(Interval $a, Interval $b, int $finishedA, int $finishedB, bool $expected): void
    {
        self::assertSame($expected, Stats::isClear($a, $b, $finishedA, $finishedB));
    }

    public function test_change_range_gives_the_relative_change_of_the_mean(): void
    {
        $range = Stats::changeRange([10, 11, 9, 10, 10, 12, 8], [5, 5, 6, 4, 5, 6, 4], 'seed-a', Confidence::forComparisons(1));

        self::assertNotNull($range);
        self::assertEqualsWithDelta(-0.5, $range->point, 1e-12);
        self::assertLessThan($range->point, $range->low);
        self::assertGreaterThan($range->point, $range->high);
        self::assertLessThan(0.0, $range->high);
    }

    public function test_change_range_gives_the_same_range_for_the_same_seed(): void
    {
        $a = [10, 11, 9, 10, 10, 12, 8];
        $b = [5, 5, 6, 4, 5, 6, 4];

        self::assertEquals(Stats::changeRange($a, $b, 'seed-a', Confidence::forComparisons(1)), Stats::changeRange($a, $b, 'seed-a', Confidence::forComparisons(1)));
        self::assertNotEquals(Stats::changeRange($a, $b, 'seed-a', Confidence::forComparisons(1)), Stats::changeRange($a, $b, 'seed-b', Confidence::forComparisons(1)));
    }

    public function test_change_range_has_no_range_for_a_zero_mean_or_an_empty_sample(): void
    {
        self::assertNull(Stats::changeRange([0, 0, 0], [1, 2, 3], 'seed-a', Confidence::forComparisons(1)));
        self::assertNull(Stats::changeRange([], [1, 2, 3], 'seed-a', Confidence::forComparisons(1)));
        self::assertNull(Stats::changeRange([1, 2, 3], [], 'seed-a', Confidence::forComparisons(1)));
    }

    public function test_change_range_skips_a_resample_whose_baseline_mean_is_zero(): void
    {
        $range = Stats::changeRange([0, 0, 0, 0, 0, 0, 0, 0, 0, 10], [5, 5, 5, 5, 5, 5, 5, 5, 5, 5], 'seed-a', Confidence::forComparisons(1));

        self::assertNotNull($range);
        self::assertEqualsWithDelta(4.0, $range->point, 1e-12);
        self::assertTrue(is_finite($range->low) && is_finite($range->high));
    }

    public function test_difference_range_gives_the_absolute_difference_of_the_means(): void
    {
        $range = Stats::differenceRange([10, 11, 9, 10, 10, 12, 8], [5, 5, 6, 4, 5, 6, 4], 'seed-a', Confidence::forComparisons(1));

        self::assertNotNull($range);
        self::assertEqualsWithDelta(-5.0, $range->point, 1e-12);
        self::assertLessThan(0.0, $range->high);
        self::assertEquals($range, Stats::differenceRange([10, 11, 9, 10, 10, 12, 8], [5, 5, 6, 4, 5, 6, 4], 'seed-a', Confidence::forComparisons(1)));
        self::assertNull(Stats::differenceRange([], [1], 'seed-a', Confidence::forComparisons(1)));
    }

    public function test_newcombe_gives_the_known_range_for_the_difference_of_two_proportions(): void
    {
        // 56 of 70 against 48 of 80: the hybrid score range of 0.2 is 0.0524 to 0.3339.
        $range = Stats::newcombe(48, 80, 56, 70, Confidence::forComparisons(1));

        self::assertNotNull($range);
        self::assertEqualsWithDelta(0.2, $range->point, 1e-12);
        self::assertEqualsWithDelta(0.0524, $range->low, 0.0001);
        self::assertEqualsWithDelta(0.3339, $range->high, 0.0001);
    }

    public function test_newcombe_is_signed_and_needs_trials_on_both_sides(): void
    {
        $range = Stats::newcombe(56, 70, 48, 80, Confidence::forComparisons(1));

        self::assertNotNull($range);
        self::assertEqualsWithDelta(-0.2, $range->point, 1e-12);
        self::assertEqualsWithDelta(-0.3339, $range->low, 0.0001);
        self::assertNull(Stats::newcombe(0, 0, 1, 2, Confidence::forComparisons(1)));
        self::assertNull(Stats::newcombe(1, 2, 0, 0, Confidence::forComparisons(1)));
    }

    /**
     * @return iterable<string, array{?Interval, ?Interval, int, int, GoalState}>
     */
    public static function goalCases(): iterable
    {
        yield 'close points with overlapping ranges are not clear' => [new Interval(8.0, 12.0, 10.0), new Interval(8.5, 12.5, 10.5), 5, 5, GoalState::NotClear];
        yield 'apart ranges are clear' => [new Interval(6.0, 9.0, 7.5), new Interval(10.0, 13.0, 11.5), 5, 5, GoalState::Clear];
        yield 'apart ranges in the other order are clear' => [new Interval(10.0, 13.0, 11.5), new Interval(6.0, 9.0, 7.5), 5, 5, GoalState::Clear];
        yield 'touching ranges overlap' => [new Interval(6.0, 9.0, 7.5), new Interval(9.0, 13.0, 11.0), 5, 5, GoalState::NotClear];
        yield 'four finished cards in a' => [new Interval(6.0, 9.0, 7.5), new Interval(10.0, 13.0, 11.5), 4, 9, GoalState::NotEnoughCards];
        yield 'four finished cards in b' => [new Interval(6.0, 9.0, 7.5), new Interval(10.0, 13.0, 11.5), 9, 4, GoalState::NotEnoughCards];
        yield 'no range in a' => [null, new Interval(10.0, 13.0, 11.5), 5, 5, GoalState::NotEnoughCards];
        yield 'no range in b' => [new Interval(6.0, 9.0, 7.5), null, 5, 5, GoalState::NotEnoughCards];
    }

    #[DataProvider('goalCases')]
    public function test_goal_state(?Interval $a, ?Interval $b, int $finishedA, int $finishedB, GoalState $expected): void
    {
        self::assertSame($expected, Stats::goalState($a, $b, $finishedA, $finishedB));
    }
}
