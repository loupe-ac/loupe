<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\MetricStatistic;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class Stats
{
    public const int MIN_FINISHED_CARDS = 5;

    private const float CLOSE_POINTS = 0.1;

    public static function wilson(int $successes, int $trials, Confidence $confidence): ?Interval
    {
        if ($trials <= 0) {
            return null;
        }

        $point = $successes / $trials;
        $z = $confidence->z();
        $zz = $z ** 2;
        $denominator = 1 + $zz / $trials;
        $centre = ($point + $zz / (2 * $trials)) / $denominator;
        $margin = $z / $denominator * sqrt($point * (1 - $point) / $trials + $zz / (4 * $trials ** 2));

        // At 0 or all successes the exact bound is 0 or 1; the float sum misses it.
        return new Interval(
            0 === $successes ? 0.0 : max(0.0, $centre - $margin),
            $successes === $trials ? 1.0 : min(1.0, $centre + $margin),
            $point,
        );
    }

    /**
     * A percentile bootstrap. The seed fixes the resamples, so a report shows the same range on every load.
     *
     * @param list<float|int> $values
     */
    public static function bootstrapMean(array $values, string $seed, Confidence $confidence, int $resamples = 2000): ?Interval
    {
        if ($resamples < 1) {
            throw new \InvalidArgumentException('A bootstrap needs at least one resample.');
        }

        $count = \count($values);
        if (0 === $count) {
            return null;
        }

        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $means = [];
        for ($i = 0; $i < $resamples; ++$i) {
            $sum = 0;
            for ($j = 0; $j < $count; ++$j) {
                $sum += $values[$randomizer->getInt(0, $count - 1)];
            }
            $means[] = $sum / $count;
        }
        sort($means);

        $last = $resamples - 1;

        return new Interval(
            (float) $means[(int) floor($confidence->lowPercentile() * $last)],
            (float) $means[(int) ceil($confidence->highPercentile() * $last)],
            array_sum($values) / $count,
        );
    }

    /**
     * A percentile bootstrap that draws whole clusters, such as the runs of one card, with replacement. A null statistic
     * reads the share of non-zero values, for a rate. The seed fixes the draws, so a page shows the same range on every load.
     *
     * @param list<list<float|int>> $clusters the known values of each cluster
     */
    public static function clusterBootstrap(array $clusters, ?MetricStatistic $statistic, string $seed, Confidence $confidence, int $resamples = 2000): ?Interval
    {
        $clusters = array_values(array_filter($clusters, static fn (array $cluster): bool => [] !== $cluster));
        if ([] === $clusters) {
            return null;
        }

        $draws = self::clusterDraws($clusters, $statistic, $seed, $resamples);
        sort($draws);
        $last = $resamples - 1;
        $all = array_merge(...$clusters);

        return new Interval(
            (float) $draws[(int) floor($confidence->lowPercentile() * $last)],
            (float) $draws[(int) ceil($confidence->highPercentile() * $last)],
            (float) (null === $statistic ? self::share($all) : ($statistic->of($all) ?? throw new \LogicException('A cluster holds a value.'))),
        );
    }

    /**
     * The statistic of each resample of whole clusters, in draw order. A median or a 90th percentile counts the picks
     * of each cluster and walks the values sorted once, so a draw never joins or sorts the values.
     *
     * @param non-empty-list<non-empty-list<float|int>> $clusters
     *
     * @return list<float>
     */
    public static function clusterDraws(array $clusters, ?MetricStatistic $statistic, string $seed, int $resamples = 2000): array
    {
        if ($resamples < 1) {
            throw new \InvalidArgumentException('A bootstrap needs at least one resample.');
        }
        if (\in_array($statistic, [MetricStatistic::Sum, MetricStatistic::Count, MetricStatistic::Max], true)) {
            throw new \InvalidArgumentException('A sum, a count and a maximum have no likely range.');
        }

        $count = \count($clusters);
        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $fraction = match ($statistic) {
            MetricStatistic::Median => 0.5,
            MetricStatistic::P90 => 0.9,
            default => null,
        };

        $values = [];
        $owners = [];
        if (null !== $fraction) {
            foreach ($clusters as $index => $cluster) {
                foreach ($cluster as $value) {
                    $values[] = $value;
                    $owners[] = $index;
                }
            }
            array_multisort($values, $owners);
        }
        $sizes = array_map(count(...), $clusters);
        // A mean adds the values of a cluster. A rate adds how many of them are non-zero.
        $sums = null === $statistic
            ? array_map(static fn (array $cluster): int => \count(array_filter($cluster, static fn (float|int $value): bool => 0 !== $value && 0.0 !== $value)), $clusters)
            : array_map(array_sum(...), $clusters);

        $draws = [];
        for ($i = 0; $i < $resamples; ++$i) {
            $picks = array_fill(0, $count, 0);
            for ($j = 0; $j < $count; ++$j) {
                ++$picks[$randomizer->getInt(0, $count - 1)];
            }

            if (null === $fraction) {
                $total = 0;
                $size = 0;
                foreach ($picks as $index => $times) {
                    $total += $times * $sums[$index];
                    $size += $times * $sizes[$index];
                }
                $draws[] = $total / $size;
                continue;
            }

            $size = 0;
            foreach ($picks as $index => $times) {
                $size += $times * $sizes[$index];
            }
            $rank = ($size - 1) * $fraction;
            $low = (int) floor($rank);
            $high = min($low + 1, $size - 1);
            $lowValue = null;
            $highValue = null;
            $seen = 0;
            foreach ($values as $position => $value) {
                $times = $picks[$owners[$position]];
                if (0 === $times) {
                    continue;
                }
                $seen += $times;
                if (null === $lowValue && $seen > $low) {
                    $lowValue = $value;
                }
                if ($seen > $high) {
                    $highValue = $value;
                    break;
                }
            }
            $draws[] = (float) ($lowValue + ($rank - $low) * ($highValue - $lowValue));
        }

        return $draws;
    }

    /** @param list<float|int> $values */
    private static function share(array $values): float
    {
        return \count(array_filter($values, static fn (float|int $value): bool => 0 !== $value && 0.0 !== $value)) / \count($values);
    }

    /**
     * A percentile bootstrap of the change of the mean of b against the mean of a, as a fraction of the mean of a. A resample whose mean of a is zero has no change and drops out.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    public static function changeRange(array $a, array $b, string $seed, Confidence $confidence, int $resamples = 2000): ?Interval
    {
        if ([] === $a || [] === $b) {
            return null;
        }

        $meanA = array_sum($a) / \count($a);
        if (0.0 === (float) $meanA) {
            return null;
        }

        $changes = self::resampledDifferences($a, $b, $seed, $resamples, true);

        return self::percentiles($changes, (array_sum($b) / \count($b) - $meanA) / $meanA, $confidence);
    }

    /**
     * A percentile bootstrap of the difference of the mean of b and the mean of a.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    public static function differenceRange(array $a, array $b, string $seed, Confidence $confidence, int $resamples = 2000): ?Interval
    {
        if ([] === $a || [] === $b) {
            return null;
        }

        return self::percentiles(
            self::resampledDifferences($a, $b, $seed, $resamples, false),
            array_sum($b) / \count($b) - array_sum($a) / \count($a),
            $confidence,
        );
    }

    /**
     * The Newcombe hybrid score range of the difference of two proportions, b minus a.
     */
    public static function newcombe(int $successesA, int $trialsA, int $successesB, int $trialsB, Confidence $confidence): ?Interval
    {
        $a = self::wilson($successesA, $trialsA, $confidence);
        $b = self::wilson($successesB, $trialsB, $confidence);
        if (null === $a || null === $b) {
            return null;
        }

        $difference = $b->point - $a->point;

        return new Interval(
            $difference - sqrt(($b->point - $b->low) ** 2 + ($a->high - $a->point) ** 2),
            $difference + sqrt(($b->high - $b->point) ** 2 + ($a->point - $a->low) ** 2),
            $difference,
        );
    }

    /**
     * Whether the data answers the goal: the ranges of the two variants do not overlap. Close points do not count.
     */
    public static function goalState(?Interval $a, ?Interval $b, int $finishedA, int $finishedB): GoalState
    {
        if (null === $a || null === $b || $finishedA < self::MIN_FINISHED_CARDS || $finishedB < self::MIN_FINISHED_CARDS) {
            return GoalState::NotEnoughCards;
        }

        return $a->high < $b->low || $b->high < $a->low ? GoalState::Clear : GoalState::NotClear;
    }

    /**
     * Whether two arms give a clear answer: a real difference, or points too close to matter.
     */
    public static function isClear(Interval $a, Interval $b, int $finishedA, int $finishedB): bool
    {
        if ($finishedA < self::MIN_FINISHED_CARDS || $finishedB < self::MIN_FINISHED_CARDS) {
            return false;
        }

        if ($a->high < $b->low || $b->high < $a->low) {
            return true;
        }

        $larger = max(abs($a->point), abs($b->point));

        return 0.0 === $larger || abs($a->point - $b->point) <= self::CLOSE_POINTS * $larger;
    }

    /**
     * @param non-empty-list<float|int> $a
     * @param non-empty-list<float|int> $b
     *
     * @return list<float>
     */
    private static function resampledDifferences(array $a, array $b, string $seed, int $resamples, bool $relative): array
    {
        if ($resamples < 1) {
            throw new \InvalidArgumentException('A bootstrap needs at least one resample.');
        }

        $countA = \count($a);
        $countB = \count($b);
        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $differences = [];
        for ($i = 0; $i < $resamples; ++$i) {
            $sumA = 0;
            for ($j = 0; $j < $countA; ++$j) {
                $sumA += $a[$randomizer->getInt(0, $countA - 1)];
            }
            $sumB = 0;
            for ($j = 0; $j < $countB; ++$j) {
                $sumB += $b[$randomizer->getInt(0, $countB - 1)];
            }
            $meanA = $sumA / $countA;
            $meanB = $sumB / $countB;
            if (!$relative) {
                $differences[] = (float) ($meanB - $meanA);
            } elseif (0.0 !== (float) $meanA) {
                $differences[] = ($meanB - $meanA) / $meanA;
            }
        }

        return $differences;
    }

    /** @param list<float> $values */
    private static function percentiles(array $values, float $point, Confidence $confidence): ?Interval
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $last = \count($values) - 1;

        return new Interval($values[(int) floor($confidence->lowPercentile() * $last)], $values[(int) ceil($confidence->highPercentile() * $last)], $point);
    }
}
