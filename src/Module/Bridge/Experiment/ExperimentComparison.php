<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One variant read against the baseline: the goal result, the change, the hold, and the change of each guard. */
final readonly class ExperimentComparison
{
    public function __construct(
        public string $variant,
        /** Held when a control holds the comparison, else NotEnoughCards, Clear or NotClear. */
        public GoalState $state,
        /** The variant with its goal point and the cards behind it. */
        public ExperimentArm $arm,
        /** True when the change is a fraction of the baseline point. False for a ratio goal, whose change is a difference of two ratios between -1 and 1. */
        public bool $relative,
        /** The change of the variant against the baseline. Null when the state is not Clear or NotClear, or when the baseline point is zero. */
        public ?Interval $change,
        /** Set when the state is Held. */
        public ?GoalHold $hold,
        /** @var list<ExperimentGuardChange> in the order of the headline guards */
        public array $guards,
    ) {
    }
}
