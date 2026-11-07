<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\GoalDirection;

/** The goal of an experiment as one row for each variant on a shared scale, around a baseline line. */
final readonly class ExperimentResultRows
{
    private const float MARGIN = 0.1;

    /** @param list<ExperimentResultRow> $rows */
    public function __construct(
        public ExperimentGoal $goal,
        public string $baseline,
        public ExperimentArm $baselineArm,
        /** The position of "no change" from 0 to 100. Null when no row has a change, so the block draws no line. */
        public ?float $baselineAt,
        public array $rows,
    ) {
    }

    /** Null when the headline has no baseline or a goal problem, so the page keeps its setup notes. */
    public static function of(ExperimentHeadline $headline, bool $sorted): ?self
    {
        $goal = $headline->goal;
        $direction = $goal->direction;
        if (null === $headline->baseline || null === $headline->baselineArm || null !== $goal->problem || null === $direction) {
            return null;
        }

        $comparisons = $headline->comparisons;
        if ($sorted) {
            $comparisons = self::sorted($comparisons, $direction);
        }

        $lowest = 0.0;
        $highest = 0.0;
        $hasChange = false;
        foreach ($comparisons as $comparison) {
            if (null === $comparison->change) {
                continue;
            }
            $hasChange = true;
            $lowest = min($lowest, $comparison->change->low);
            $highest = max($highest, $comparison->change->high);
        }
        $span = $highest - $lowest;
        if ($span <= 0.0) {
            [$from, $to] = [-1.0, 1.0];
        } else {
            [$from, $to] = [$lowest - $span * self::MARGIN, $highest + $span * self::MARGIN];
        }
        $position = static fn (float $value): float => round(($value - $from) / ($to - $from) * 100, 2);

        $rows = [];
        foreach ($comparisons as $comparison) {
            $change = $comparison->change;
            $rows[] = new ExperimentResultRow(
                $comparison,
                self::tone($comparison, $direction),
                null === $change ? null : new ExperimentResultStrip($position($change->low), $position($change->point), $position($change->high)),
            );
        }

        return new self($goal, $headline->baseline, $headline->baselineArm, $hasChange ? $position(0.0) : null, $rows);
    }

    /**
     * @param list<ExperimentComparison> $comparisons
     *
     * @return list<ExperimentComparison>
     */
    private static function sorted(array $comparisons, GoalDirection $direction): array
    {
        $with = array_values(array_filter($comparisons, static fn (ExperimentComparison $comparison): bool => null !== $comparison->change));
        $without = array_values(array_filter($comparisons, static fn (ExperimentComparison $comparison): bool => null === $comparison->change));
        $point = static fn (ExperimentComparison $comparison): float => $comparison->change->point ?? 0.0;
        usort($with, static fn (ExperimentComparison $a, ExperimentComparison $b): int => GoalDirection::Lower === $direction
            ? $point($a) <=> $point($b)
            : $point($b) <=> $point($a));

        return [...$with, ...$without];
    }

    private static function tone(ExperimentComparison $comparison, GoalDirection $direction): ExperimentResultTone
    {
        $change = $comparison->change;
        if (null === $change) {
            return ExperimentResultTone::None;
        }
        if (0.0 === $change->point) {
            return ExperimentResultTone::Level;
        }

        $better = GoalDirection::Lower === $direction ? $change->point < 0 : $change->point > 0;

        return $better ? ExperimentResultTone::Better : ExperimentResultTone::Worse;
    }
}
