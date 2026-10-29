<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\MetricValueType;

final class ExperimentHeadlineBuilder
{
    /**
     * @param list<string>            $variantNames the variants in the order the report shows them
     * @param array<string, int>      $finished     variant => finished cards
     * @param list<ExperimentMetric>  $guards
     * @param ?string                 $baseline     null for an experiment that names none, which has no comparison
     * @param ?GoalState              $problem      UnknownMetric or NoDirection when the rule names a goal that cannot be answered
     * @param list<ExperimentControl> $controls     a control on a variant or on the baseline that does not pass holds the comparison, before the card floor is read
     */
    public static function build(string $experiment, array $variantNames, array $finished, ExperimentMetric $goal, ?GoalDirection $direction, array $guards, ?string $baseline, Confidence $confidence, ?GoalState $problem = null, array $controls = []): ExperimentHeadline
    {
        $others = null === $baseline ? [] : array_values(array_filter($variantNames, static fn (string $name): bool => $name !== $baseline));
        $problem ??= null === $direction ? GoalState::NoDirection : null;

        return new ExperimentHeadline(
            new ExperimentGoal($goal, $direction, $problem),
            $baseline,
            $confidence,
            null === $baseline ? null : self::arm($baseline, $finished, $goal),
            null === $baseline ? [] : array_map(static fn (string $variant): ExperimentComparison => self::compare($experiment, $variant, $baseline, $finished, $goal, $problem, $guards, $confidence, $controls), $others),
            array_map(static fn (ExperimentMetric $guard): ExperimentGuard => new ExperimentGuard($guard, null === $baseline ? null : $guard->for($baseline)?->point), $guards),
            $controls,
        );
    }

    /**
     * @param array<string, int>      $finished
     * @param list<ExperimentMetric>  $guards
     * @param list<ExperimentControl> $controls
     */
    private static function compare(string $experiment, string $variant, string $baseline, array $finished, ExperimentMetric $goal, ?GoalState $problem, array $guards, Confidence $confidence, array $controls): ExperimentComparison
    {
        $relative = MetricValueType::Ratio !== $goal->valueType();
        $arm = self::arm($variant, $finished, $goal);
        $base = self::arm($baseline, $finished, $goal);
        $hold = ControlEvaluator::hold($controls, $variant, $baseline);
        $change = null;
        if (null !== $hold) {
            $state = GoalState::Held;
        } elseif (null !== $problem) {
            $state = $problem;
        } else {
            $state = Stats::goalState($goal->for($baseline), $goal->for($variant), $base->finishedCards, $arm->finishedCards);
            if (GoalState::NotEnoughCards !== $state) {
                $change = $relative
                    ? Stats::changeRange($goal->samples[$baseline] ?? [], $goal->samples[$variant] ?? [], $experiment.':'.$goal->key.':change:'.$variant, $confidence)
                    : self::proportions($goal, $baseline, $variant, $confidence);
            }
        }

        return new ExperimentComparison(
            $variant,
            $state,
            $arm,
            $relative,
            $change,
            $hold,
            array_map(static fn (ExperimentMetric $guard): ExperimentGuardChange => new ExperimentGuardChange(
                $guard->key,
                $guard->for($variant)?->point,
                MetricValueType::Ratio === $guard->valueType()
                    ? self::proportions($guard, $baseline, $variant, $confidence)
                    : Stats::differenceRange($guard->samples[$baseline] ?? [], $guard->samples[$variant] ?? [], $experiment.':'.$guard->key.':guard:'.$variant, $confidence),
            ), $guards),
        );
    }

    /** The difference of the ratio of one variant minus the ratio of another, from the 0 and 1 samples behind them. */
    private static function proportions(ExperimentMetric $metric, string $from, string $to, Confidence $confidence): ?Interval
    {
        $a = $metric->samples[$from] ?? [];
        $b = $metric->samples[$to] ?? [];

        return Stats::newcombe((int) array_sum($a), \count($a), (int) array_sum($b), \count($b), $confidence);
    }

    /**
     * The point of a variant, and the cards behind it: the cards of its sample, up to its finished cards.
     *
     * @param array<string, int> $finished
     */
    private static function arm(string $variant, array $finished, ExperimentMetric $metric): ExperimentArm
    {
        return new ExperimentArm($variant, $metric->for($variant)?->point, min($finished[$variant] ?? 0, \count($metric->samples[$variant] ?? [])));
    }
}
