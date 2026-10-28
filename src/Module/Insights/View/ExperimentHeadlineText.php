<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Metric\MetricValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The sentences and figures of the goal panel and the Guards table, as text. */
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

    /** The answer of the goal, such as "sonnet is 22% faster than opus". */
    public function sentence(ExperimentGoal $goal): string
    {
        if (GoalState::NotEnoughCards === $goal->state) {
            return $this->translator->trans('bridge.experiments.goal.sentence.not_enough');
        }
        if (null === $goal->winner) {
            return $this->translator->trans('bridge.experiments.goal.sentence.level');
        }

        if (null !== $goal->change && 0.0 === round(abs($goal->change->point) * 100)) {
            return $this->translator->trans('bridge.experiments.goal.sentence.level');
        }

        $parameters = ['%winner%' => $goal->winner, '%other%' => self::other($goal)];
        if (null === $goal->change) {
            return $this->translator->trans('bridge.experiments.goal.sentence.ahead', $parameters);
        }
        if (!$goal->relative) {
            $parameters['%count%'] = $this->points($goal->change->point);

            return $this->translator->trans('bridge.experiments.goal.sentence.points_'.$goal->direction->value, $parameters);
        }

        $word = MetricValueType::Duration === $goal->metric->valueType()
            ? (GoalDirection::Lower === $goal->direction ? 'faster' : 'slower')
            : $goal->direction->value;

        return $this->translator->trans('bridge.experiments.goal.sentence.'.$word, $parameters + ['%change%' => $this->percent(abs($goal->change->point))]);
    }

    /** The range of the change of the winner, such as "9% to 33% lower". Null when the goal has no change. */
    public function range(ExperimentGoal $goal): ?string
    {
        $change = $goal->change;
        if (null === $change) {
            return null;
        }

        if ($change->low < 0 && $change->high > 0) {
            return $this->translator->trans('bridge.experiments.goal.range.across', [
                '%low%' => $this->magnitude($change->low, $goal->relative, true),
                '%lowWord%' => $this->translator->trans('bridge.experiments.goal.lower'),
                '%high%' => $this->magnitude($change->high, $goal->relative, true),
                '%highWord%' => $this->translator->trans('bridge.experiments.goal.higher'),
            ]);
        }

        [$near, $far, $word] = $change->high <= 0 ? [$change->high, $change->low, 'lower'] : [$change->low, $change->high, 'higher'];

        return $this->translator->trans('bridge.experiments.goal.range.between', [
            '%near%' => $this->magnitude($near, $goal->relative, false),
            '%far%' => $this->magnitude($far, $goal->relative, true),
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

    /** The first column of a guard: the two rates, or the signed change of the second variant. Null when a variant has no data. */
    public function guardChange(ExperimentGuard $guard): ?string
    {
        if (MetricValueType::Ratio === $guard->metric->valueType()) {
            [$first, $second] = [$guard->arms[0]->point ?? null, $guard->arms[1]->point ?? null];
            if (null === $first || null === $second) {
                return null;
            }

            return $this->translator->trans('bridge.experiments.guards.rates', ['%from%' => $this->percent($first), '%to%' => $this->percent($second)]);
        }

        return null === $guard->change ? null : $this->signed($guard->metric, $guard->change->point);
    }

    /** The range of the change of a guard, such as "−12 to 0 pp". Null when a variant has no data. */
    public function guardRange(ExperimentGuard $guard): ?string
    {
        $change = $guard->change;
        if (null === $change) {
            return null;
        }
        if (MetricValueType::Ratio === $guard->metric->valueType()) {
            return $this->translator->trans('bridge.experiments.guards.range_points', [
                '%low%' => $this->signedPoints($change->low),
                '%high%' => $this->signedPoints($change->high),
            ]);
        }

        return $this->translator->trans('bridge.experiments.guards.range', [
            '%low%' => $this->signed($guard->metric, $change->low),
            '%high%' => $this->signed($guard->metric, $change->high),
        ]);
    }

    private static function other(ExperimentGoal $goal): string
    {
        foreach ($goal->arms as $arm) {
            if ($arm->variant !== $goal->winner) {
                return $arm->variant;
            }
        }

        return '';
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
