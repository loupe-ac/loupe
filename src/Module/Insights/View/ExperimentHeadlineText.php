<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The change, the range and the Result text of the goal table, and the figures of the Controls and Guards tables. */
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

    /** The change of the variant against the baseline, such as "12% cheaper" or "3 pp higher". The sign reads the same in every row. Null when the comparison has no change. */
    public function change(ExperimentGoal $goal, ExperimentComparison $comparison): ?string
    {
        $change = $comparison->change;
        if (null === $change) {
            return null;
        }
        if (0.0 === $change->point) {
            return $this->translator->trans('bridge.experiments.goal.change.level');
        }

        $less = $change->point < 0;
        if (!$comparison->relative) {
            return $this->translator->trans('bridge.experiments.goal.change.points_'.($less ? 'lower' : 'higher'), ['%count%' => $this->points($change->point)]);
        }

        $word = match ($goal->metric->valueType()) {
            MetricValueType::Duration => $less ? 'faster' : 'slower',
            MetricValueType::Money => $less ? 'cheaper' : 'dearer',
            MetricValueType::Count => $less ? 'fewer' : 'more',
            default => $less ? 'lower' : 'higher',
        };

        return $this->translator->trans('bridge.experiments.goal.change.'.$word, ['%change%' => $this->changePercent($change->point)]);
    }

    /** The chip text of a comparison that is short of cards, such as "lsp-skill needs 2 more cards". Each side below the floor is named. */
    public function needs(ExperimentComparison $comparison, ExperimentArm $baseline): string
    {
        $parts = [];
        foreach ([$baseline, $comparison->arm] as $arm) {
            $missing = Stats::MIN_FINISHED_CARDS - $arm->finishedCards;
            if ($missing > 0) {
                $parts[] = $this->translator->trans('bridge.experiments.goal.needs', ['%variant%' => $arm->variant, '%count%' => $missing]);
            }
        }

        return [] === $parts ? $this->translator->trans('bridge.experiments.goal.not_enough') : implode(', ', $parts);
    }

    /** Why a comparison names no result: how many controls on its two arms hold it. */
    public function holdReason(ExperimentComparison $comparison): string
    {
        $hold = $comparison->hold ?? throw new \LogicException('A held comparison has a hold.');
        $key = match ($hold->reason) {
            ControlResult::UnknownMetric => 'unknown',
            ControlResult::NoData => 'no_data',
            default => 'failed',
        };

        return $this->translator->trans('bridge.experiments.goal.hold.detail.'.$key, ['%count%' => \count($hold->controls), '%total%' => $hold->total]);
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

    /** The level of the ranges, such as "97.5%". */
    public function level(Confidence $confidence): string
    {
        return $this->percent($confidence->level, 1);
    }

    /** The level as a count in 100, such as "97.5". */
    public function levelOutOf100(Confidence $confidence): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string) $formatter->format($confidence->level * 100);
    }

    /** The change of a guard from the baseline to one variant with its range, such as "+5 pp (\u{2212}6 to +15)". Null when an arm has no data. */
    public function guardChange(ExperimentGuard $guard, ExperimentGuardChange $change): ?string
    {
        $range = $change->change;
        if (null === $range) {
            return null;
        }
        if (MetricValueType::Ratio === $guard->metric->valueType()) {
            return $this->translator->trans('bridge.experiments.guards.change_points', [
                '%change%' => $this->signedPoints($range->point),
                '%low%' => $this->signedPoints($range->low),
                '%high%' => $this->signedPoints($range->high),
            ]);
        }

        return $this->translator->trans('bridge.experiments.guards.change_range', [
            '%change%' => $this->signed($guard->metric, $range->point),
            '%low%' => $this->signed($guard->metric, $range->low),
            '%high%' => $this->signed($guard->metric, $range->high),
        ]);
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

    /** A change that rounds to 0% reads "under 1%". */
    private function changePercent(float $fraction): string
    {
        if (0.0 === round(abs($fraction) * 100)) {
            return $this->translator->trans('bridge.experiments.goal.under', ['%value%' => $this->percent(0.01)]);
        }

        return $this->percent(abs($fraction));
    }

    private function percent(float $fraction, int $digits = 0): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::PERCENT);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $digits);

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
