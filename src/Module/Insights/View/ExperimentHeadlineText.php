<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The sentences and figures of the goal panel, the Controls table and the Guards table, as text. */
final readonly class ExperimentHeadlineText
{
    private const string MINUS = "\u{2212}";

    private MetricValueFormatter $values;

    public function __construct(
        private TranslatorInterface $translator,
        private string $locale,
    ) {
        $this->values = new MetricValueFormatter($locale);
    }

    /** The answer of one comparison, such as "sonnet is 22% faster than opus". The change reads the variant against the baseline. */
    public function sentence(ExperimentGoal $goal, ExperimentComparison $comparison, ExperimentArm $baseline): string
    {
        if (GoalState::NotEnoughCards === $comparison->state) {
            return $this->translator->trans('bridge.experiments.goal.sentence.not_enough');
        }
        $leader = self::leader($goal, $comparison, $baseline);
        if (null === $leader) {
            return $this->translator->trans('bridge.experiments.goal.sentence.level');
        }

        $change = $comparison->change;
        if (null === $change) {
            $parameters = ['%winner%' => $leader, '%other%' => $leader === $baseline->variant ? $comparison->variant : $baseline->variant];

            return $this->translator->trans('bridge.experiments.goal.sentence.ahead', $parameters);
        }

        $parameters = ['%winner%' => $comparison->variant, '%other%' => $baseline->variant];
        $less = $change->point < 0;
        if (!$comparison->relative) {
            $parameters['%count%'] = $this->points($change->point);

            return $this->translator->trans('bridge.experiments.goal.sentence.points_'.($less ? 'lower' : 'higher'), $parameters);
        }

        $word = match ($goal->metric->valueType()) {
            MetricValueType::Duration => $less ? 'faster' : 'slower',
            MetricValueType::Money => $less ? 'cheaper' : 'dearer',
            MetricValueType::Count => $less ? 'fewer' : 'more',
            default => $less ? 'lower' : 'higher',
        };

        return $this->translator->trans('bridge.experiments.goal.sentence.'.$word, $parameters + ['%change%' => $this->changePercent($change->point)]);
    }

    /** True when the variant is the better of the two in the direction of the goal. */
    public function leads(ExperimentGoal $goal, ExperimentComparison $comparison, ExperimentArm $baseline): bool
    {
        return $comparison->variant === self::leader($goal, $comparison, $baseline);
    }

    /** The large line of a held comparison, such as "Treatment not applied". */
    public function holdSentence(ExperimentComparison $comparison): string
    {
        $hold = $comparison->hold ?? throw new \LogicException('A held comparison has a hold.');

        return match ($hold->reason) {
            ControlResult::UnknownMetric => $this->translator->trans('bridge.experiments.goal.hold.unknown', ['%metric%' => $hold->controls[0]->metric]),
            ControlResult::NoData => $this->translator->trans('bridge.experiments.goal.hold.no_data'),
            default => $this->translator->trans('bridge.experiments.goal.hold.failed'),
        };
    }

    /** The line under a held comparison: how many controls hold it, and the goal value of the baseline and the variant. */
    public function holdDetail(ExperimentGoal $goal, ExperimentComparison $comparison, ExperimentArm $baseline): string
    {
        $hold = $comparison->hold ?? throw new \LogicException('A held comparison has a hold.');
        $key = match ($hold->reason) {
            ControlResult::UnknownMetric => 'unknown',
            ControlResult::NoData => 'no_data',
            default => 'failed',
        };
        $parts = [$this->translator->trans('bridge.experiments.goal.hold.detail.'.$key, ['%count%' => \count($hold->controls), '%total%' => $hold->total])];
        $values = [];
        foreach ([$baseline, $comparison->arm] as $arm) {
            $point = $this->point($goal->metric, $arm->point);
            if (null !== $point) {
                $values[] = $point.' ('.$arm->variant.')';
            }
        }
        if (2 === \count($values)) {
            $parts[] = $values[0].' '.$this->translator->trans('bridge.experiments.goal.against').' '.$values[1].'.';
        }

        return implode(' ', $parts);
    }

    /** The name of a control and its expectation, such as "Harness events: diagnostics > 0". */
    public function controlLabel(ExperimentControl $control): string
    {
        $key = $control->metric;
        $name = null;
        foreach ([Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::HarnessEvents] as $family) {
            if (str_starts_with($key, $family->value.':')) {
                $name = $this->translator->trans('analytics.metrics.metric.'.str_replace('-', '_', $family->value).'_named', [Metric::HarnessEvents === $family ? '%kind%' : '%bucket%' => substr($key, \strlen($family->value) + 1)]);
                break;
            }
        }
        $name ??= $control->templateLabel ?? (ControlResult::UnknownMetric === $control->result ? $key : $this->translator->trans(ExperimentMetric::COVERED_COST === $key ? 'bridge.experiments.metric.'.$key : 'analytics.metrics.metric.'.$key));

        return $name.' '.$control->expect;
    }

    /** A share of cards, such as "50%". */
    public function share(float $share): string
    {
        return $this->percent($share);
    }

    /** The range of the change of the variant against the baseline, such as "9% to 33% lower". Null when the comparison has no change. */
    public function range(ExperimentComparison $comparison): ?string
    {
        $change = $comparison->change;
        if (null === $change) {
            return null;
        }

        if ($change->low < 0 && $change->high > 0) {
            return $this->translator->trans('bridge.experiments.goal.range.across', [
                '%low%' => $this->magnitude($change->low, $comparison->relative, true),
                '%lowWord%' => $this->translator->trans('bridge.experiments.goal.lower'),
                '%high%' => $this->magnitude($change->high, $comparison->relative, true),
                '%highWord%' => $this->translator->trans('bridge.experiments.goal.higher'),
            ]);
        }

        [$near, $far, $word] = $change->high <= 0 ? [$change->high, $change->low, 'lower'] : [$change->low, $change->high, 'higher'];

        return $this->translator->trans('bridge.experiments.goal.range.between', [
            '%near%' => $this->magnitude($near, $comparison->relative, false),
            '%far%' => $this->magnitude($far, $comparison->relative, true),
            '%word%' => $this->translator->trans('bridge.experiments.goal.'.$word),
        ]);
    }

    /** A point of a goal in the unit of its metric, such as "4.2 h". Null when the variant has no data. */
    public function point(ExperimentMetric $metric, ?float $point): ?string
    {
        if (null === $point || null === $metric->shape) {
            return null;
        }

        return MetricValueType::Ratio === $metric->valueType() ? $this->percent($point) : $this->values->format($point, $metric->shape);
    }

    /** The first column of a guard: the two rates, or the signed change of the variant. Null when an arm has no data. */
    public function guardChange(ExperimentGuard $guard, ExperimentGuardChange $change): ?string
    {
        if (MetricValueType::Ratio === $guard->metric->valueType()) {
            if (null === $guard->baselinePoint || null === $change->point) {
                return null;
            }

            return $this->translator->trans('bridge.experiments.guards.rates', ['%from%' => $this->percent($guard->baselinePoint), '%to%' => $this->percent($change->point)]);
        }

        return null === $change->change ? null : $this->signed($guard->metric, $change->change->point);
    }

    /** The range of the change of a guard, such as "−12 to 0 pp". Null when an arm has no data. */
    public function guardRange(ExperimentGuard $guard, ExperimentGuardChange $change): ?string
    {
        $range = $change->change;
        if (null === $range) {
            return null;
        }
        if (MetricValueType::Ratio === $guard->metric->valueType()) {
            return $this->translator->trans('bridge.experiments.guards.range_points', [
                '%low%' => $this->signedPoints($range->low),
                '%high%' => $this->signedPoints($range->high),
            ]);
        }

        return $this->translator->trans('bridge.experiments.guards.range', [
            '%low%' => $this->signed($guard->metric, $range->low),
            '%high%' => $this->signed($guard->metric, $range->high),
        ]);
    }

    /** The variant with the better point in the direction of the goal. Null when the points are equal, a point is missing, or the goal has no direction. */
    private static function leader(ExperimentGoal $goal, ExperimentComparison $comparison, ExperimentArm $baseline): ?string
    {
        $variant = $comparison->arm->point;
        if (null === $variant || null === $baseline->point || $variant === $baseline->point || null === $goal->direction) {
            return null;
        }

        return (GoalDirection::Lower === $goal->direction ? $variant < $baseline->point : $variant > $baseline->point) ? $comparison->variant : $baseline->variant;
    }

    private function magnitude(float $value, bool $relative, bool $unit): string
    {
        if ($relative) {
            return $this->percent(abs($value));
        }
        $points = $this->points($value);

        return $unit ? $this->translator->trans('bridge.experiments.goal.points', ['%count%' => $points]) : (string) $points;
    }

    private function points(float $ratio): int
    {
        return (int) round(abs($ratio) * 100);
    }

    /** A leading variant differs from the other, so a change that rounds to 0% reads "under 1%". */
    private function changePercent(float $fraction): string
    {
        if (0.0 === round(abs($fraction) * 100)) {
            return $this->translator->trans('bridge.experiments.goal.under', ['%value%' => $this->percent(0.01)]);
        }

        return $this->percent(abs($fraction));
    }

    private function percent(float $fraction): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::PERCENT);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return (string) $formatter->format($fraction);
    }

    private function signedPoints(float $ratio): string
    {
        $points = (int) round($ratio * 100);

        return (0 === $points ? '' : ($points < 0 ? self::MINUS : '+')).abs($points);
    }

    private function signed(ExperimentMetric $metric, float $value): string
    {
        $text = null === $metric->shape ? (string) abs($value) : ($this->values->format(abs($value), $metric->shape) ?? '');

        return (1 === preg_match('/[1-9]/', $text) ? ($value < 0 ? self::MINUS : '+') : '').$text;
    }
}
