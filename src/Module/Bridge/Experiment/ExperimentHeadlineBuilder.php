<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\MetricValueType;

final class ExperimentHeadlineBuilder
{
    /**
     * @param list<string>            $variantNames sorted, so the first two are the ones compared
     * @param array<string, int>      $finished     variant => finished cards
     * @param list<ExperimentMetric>  $guards
     * @param ?GoalState              $problem      UnknownMetric or NoDirection when the rule names a goal that cannot be answered
     * @param list<ExperimentControl> $controls     a control that does not pass holds the goal, before the card floor is read
     */
    public static function build(string $experiment, array $variantNames, array $finished, ExperimentMetric $goal, ?GoalDirection $direction, array $guards, ?GoalState $problem = null, array $controls = []): ExperimentHeadline
    {
        $names = \array_slice($variantNames, 0, 2);
        $hold = ControlEvaluator::hold($controls);

        return new ExperimentHeadline(
            self::answer($experiment, $names, $finished, $goal, $direction, $problem, $hold),
            array_map(static fn (ExperimentMetric $guard): ExperimentGuard => self::guard($experiment, $names, $finished, $guard), $guards),
            $controls,
        );
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $finished
     */
    private static function answer(string $experiment, array $names, array $finished, ExperimentMetric $metric, ?GoalDirection $direction, ?GoalState $problem, ?GoalHold $hold): ExperimentGoal
    {
        $relative = MetricValueType::Ratio !== $metric->valueType();
        if (null !== $hold) {
            return new ExperimentGoal($metric, $direction, GoalState::Held, self::arms($names, $finished, $metric), null, null, $relative, $hold);
        }
        if (null === $problem && null !== $direction) {
            return self::goal($experiment, $names, $finished, $metric, $direction);
        }

        return new ExperimentGoal($metric, $direction, $problem ?? GoalState::NoDirection, self::arms($names, $finished, $metric), null, null, $relative);
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
            ? Stats::changeRange($metric->samples[$other] ?? [], $metric->samples[$winner] ?? [], $experiment.':'.$metric->key.':change', Confidence::forComparisons(1))
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
                : Stats::differenceRange($metric->samples[$a] ?? [], $metric->samples[$b] ?? [], $experiment.':'.$metric->key.':guard', Confidence::forComparisons(1));
        }

        return new ExperimentGuard($metric, self::arms($names, $finished, $metric), $change);
    }

    /** The difference of the ratio of one variant minus the ratio of another, from the 0 and 1 samples behind them. */
    private static function proportions(ExperimentMetric $metric, string $from, string $to): ?Interval
    {
        $a = $metric->samples[$from] ?? [];
        $b = $metric->samples[$to] ?? [];

        return Stats::newcombe((int) array_sum($a), \count($a), (int) array_sum($b), \count($b), Confidence::forComparisons(1));
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
