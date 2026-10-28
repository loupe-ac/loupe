<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** What the experiment tries to improve, and how far the data answers it. */
final readonly class ExperimentGoal
{
    public function __construct(
        public ExperimentMetric $metric,
        /** Null when the rule names no better way. */
        public ?GoalDirection $direction,
        public GoalState $state,
        /**
         * The first two variants by name, fewer when the experiment has fewer.
         *
         * @var list<ExperimentArm>
         */
        public array $arms,
        /** The variant whose point is better in the direction. Null when the goal is invalid, the cards are too few, or the points are equal. */
        public ?string $winner,
        /** The change of the winner against the other variant. Null with no winner, or when the other point is zero. */
        public ?Interval $change,
        /** True when the change is a fraction of the other point. False for a ratio goal, whose change is a difference of two ratios between -1 and 1. */
        public bool $relative,
    ) {
    }
}
