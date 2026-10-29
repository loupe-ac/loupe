<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The answer at the top of the report, from the first two variants by name, with the controls that judge it. */
final readonly class ExperimentHeadline
{
    public function __construct(
        public ExperimentGoal $goal,
        /** @var list<ExperimentGuard> */
        public array $guards,
        /** @var list<ExperimentControl> */
        public array $controls = [],
    ) {
    }
}
