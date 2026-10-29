<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

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
