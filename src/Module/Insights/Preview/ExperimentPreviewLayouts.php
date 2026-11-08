<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Insights\View\ExperimentHeadlineText;
use App\Module\Insights\View\ExperimentResultRows;
use App\Module\Insights\View\PublicExperimentResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns the kept report of a shared experiment into the rows or cards of its image. A goal that names a baseline, a
 * metric and a better direction gives one row for each variant. Every other state gives value cards, the same for the
 * state that the share page hides: no baseline, a goal key that is not a metric, and a goal with no direction.
 */
final readonly class ExperimentPreviewLayouts
{
    private const int BASELINE_BUDGET = 16;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function build(PublicExperimentResult $result): ExperimentPreviewLayout
    {
        $text = new ExperimentHeadlineText($this->translator, \Locale::getDefault());
        $headline = $result->headline;
        $rows = null === $headline ? null : ExperimentResultRows::of($headline, sorted: true);
        if (null !== $headline && null !== $rows) {
            return $this->rows($headline, $rows, $text);
        }

        $problem = $headline?->goal->problem;
        $direction = $headline?->goal->direction;
        $metric = null === $headline || GoalState::UnknownMetric === $problem ? ($result->metrics[ExperimentMetric::COST] ?? null) : $headline->goal->metric;
        if (null === $headline || GoalState::UnknownMetric === $problem) {
            $direction = GoalDirection::Lower;
        }
        $sorted = null !== $direction && GoalState::NoDirection !== $problem;

        return $this->cards($result, $metric, $direction, $sorted, $text);
    }

    private function rows(ExperimentHeadline $headline, ExperimentResultRows $all, ExperimentHeadlineText $text): ExperimentPreviewLayout
    {
        $comparisons = array_map(static fn ($row): ExperimentComparison => $row->comparison, $all->rows);
        $shown = new ExperimentHeadline(
            $headline->goal,
            $headline->baseline,
            $headline->confidence,
            $headline->baselineArm,
            \array_slice($comparisons, 0, ExperimentPreviewLayout::LIMIT),
            $headline->guards,
            $headline->controls,
        );
        $rows = ExperimentResultRows::of($shown, sorted: false) ?? $all;
        $metric = $headline->goal->metric;
        $items = [];
        foreach ($rows->rows as $row) {
            $comparison = $row->comparison;
            $items[] = new ExperimentPreviewItem(
                $comparison->variant,
                $text->point($metric, $comparison->arm->point) ?? '–',
                $text->signedChange($comparison),
                $row->tone,
                $text->resultLabel($comparison, $rows->baselineArm),
                self::chipTone($comparison),
                $row->strip,
            );
        }

        $direction = $headline->goal->direction ?? throw new \LogicException('Rows need a better direction.');
        $against = $this->translator->trans('insights.public_link.preview.image.against', [
            '%baseline%' => PreviewText::fit($rows->baseline, self::BASELINE_BUDGET),
            '%value%' => $text->point($metric, $rows->baselineArm->point) ?? '–',
        ]);

        return new ExperimentPreviewLayout(
            true,
            $text->metricName($metric),
            $against.' · '.$this->direction($direction),
            $items,
            max(0, \count($comparisons) - ExperimentPreviewLayout::LIMIT),
            $rows->baselineAt,
        );
    }

    private function cards(PublicExperimentResult $result, ?ExperimentMetric $metric, ?GoalDirection $direction, bool $sorted, ExperimentHeadlineText $text): ExperimentPreviewLayout
    {
        $cards = [];
        foreach ($result->variants as $variant) {
            $point = $metric?->for($variant->name)?->point;
            $cards[] = [$variant->name, $point];
        }
        if ($sorted) {
            $withValue = array_values(array_filter($cards, static fn (array $card): bool => null !== $card[1]));
            $without = array_values(array_filter($cards, static fn (array $card): bool => null === $card[1]));
            usort($withValue, static fn (array $a, array $b): int => GoalDirection::Higher === $direction ? $b[1] <=> $a[1] : $a[1] <=> $b[1]);
            $cards = [...$withValue, ...$without];
        }

        $items = [];
        foreach (\array_slice($cards, 0, ExperimentPreviewLayout::LIMIT) as [$name, $point]) {
            $items[] = new ExperimentPreviewItem($name, null === $metric ? '–' : ($text->point($metric, $point) ?? '–'));
        }

        return new ExperimentPreviewLayout(
            false,
            null === $metric ? $result->experiment : $text->metricName($metric),
            null === $direction ? '' : $this->direction($direction),
            $items,
            max(0, \count($cards) - ExperimentPreviewLayout::LIMIT),
        );
    }

    private function direction(GoalDirection $direction): string
    {
        return $this->translator->trans('insights.public_link.preview.image.better_'.$direction->value);
    }

    private static function chipTone(ExperimentComparison $comparison): string
    {
        return match ($comparison->state) {
            GoalState::Clear => 'ok',
            GoalState::NotClear => 'pending',
            GoalState::Held => 'failed' === $comparison->hold?->reason->value ? 'failed' : 'neutral',
            default => 'neutral',
        };
    }
}
