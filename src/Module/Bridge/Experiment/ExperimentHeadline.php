<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The answer at the top of the report: each variant against the baseline, at one range level, with the controls that judge it. */
final readonly class ExperimentHeadline
{
    public function __construct(
        public ExperimentGoal $goal,
        /** Null when the experiment names no baseline. */
        public ?string $baseline,
        public Confidence $confidence,
        /** Null when the experiment names no baseline. */
        public ?ExperimentArm $baselineArm,
        /**
         * One for each variant other than the baseline, in the order of the variants. Empty with no baseline.
         *
         * @var list<ExperimentComparison>
         */
        public array $comparisons,
        /** @var list<ExperimentGuard> */
        public array $guards,
        /** @var list<ExperimentControl> */
        public array $controls = [],
    ) {
    }
}
