<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\MetricValueType;

final class ExperimentHeadlineBuilder
{
    /**
     * @param list<string>           $variantNames sorted, so the first two are the ones compared
     * @param array<string, int>     $finished     variant => finished cards
     * @param list<ExperimentMetric> $guards
     */
    public static function build(string $experiment, array $variantNames, array $finished, ExperimentMetric $goal, GoalDirection $direction, array $guards): ExperimentHeadline
    {
        $names = \array_slice($variantNames, 0, 2);

        return new ExperimentHeadline(
            self::goal($experiment, $names, $finished, $goal, $direction),
            array_map(static fn (ExperimentMetric $guard): ExperimentGuard => self::guard($experiment, $names, $finished, $guard), $guards),
        );
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $finished
     */
    private static function goal(string $experiment, array $names, array $finished, ExperimentMetric $metric, GoalDirection $direction): ExperimentGoal
    {
        $behind = array_map(static fn (string $name): int => min($finished[$name] ?? 0, \count($metric->samples[$name] ?? [])), $names);
        $arms = self::arms($names, array_combine($names, $behind), $metric);
        $relative = MetricValueType::Ratio !== $metric->valueType();
        if (2 !== \count($names)) {
            return new ExperimentGoal($metric, $direction, GoalState::NotEnoughCards, $arms, null, null, $relative);
        }

        [$a, $b] = $names;
        $state = Stats::goalState($metric->for($a), $metric->for($b), $behind[0], $behind[1]);
        $pointA = $metric->for($a)?->point;
        $pointB = $metric->for($b)?->point;
        if (GoalState::NotEnoughCards === $state || null === $pointA || null === $pointB || $pointA === $pointB) {
            return new ExperimentGoal($metric, $direction, $state, $arms, null, null, $relative);
        }

        $aWins = GoalDirection::Lower === $direction ? $pointA < $pointB : $pointA > $pointB;
        [$winner, $other] = $aWins ? [$a, $b] : [$b, $a];
        $change = $relative
            ? Stats::changeRange($metric->samples[$other] ?? [], $metric->samples[$winner] ?? [], $experiment.':'.$metric->key.':change')
            : self::proportions($metric, $other, $winner);

        return new ExperimentGoal($metric, $direction, $state, $arms, $winner, $change, $relative);
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $finished
     */
    private static function guard(string $experiment, array $names, array $finished, ExperimentMetric $metric): ExperimentGuard
    {
        $change = null;
        if (2 === \count($names)) {
            [$a, $b] = $names;
            $change = MetricValueType::Ratio === $metric->valueType()
                ? self::proportions($metric, $a, $b)
                : Stats::differenceRange($metric->samples[$a] ?? [], $metric->samples[$b] ?? [], $experiment.':'.$metric->key.':guard');
        }

        return new ExperimentGuard($metric, self::arms($names, $finished, $metric), $change);
    }

    /** The difference of the ratio of one variant minus the ratio of another, from the 0 and 1 samples behind them. */
    private static function proportions(ExperimentMetric $metric, string $from, string $to): ?Interval
    {
        $a = $metric->samples[$from] ?? [];
        $b = $metric->samples[$to] ?? [];

        return Stats::newcombe((int) array_sum($a), \count($a), (int) array_sum($b), \count($b));
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $finished
     *
     * @return list<ExperimentArm>
     */
    private static function arms(array $names, array $finished, ExperimentMetric $metric): array
    {
        return array_map(static fn (string $name): ExperimentArm => new ExperimentArm($name, $metric->for($name)?->point, $finished[$name] ?? 0), $names);
    }
}
