<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** What the experiment tries to improve, and why its rule cannot be answered when it names an unusable goal. */
final readonly class ExperimentGoal
{
    public function __construct(
        public ExperimentMetric $metric,
        /** Null when the rule names no better way. */
        public ?GoalDirection $direction,
        /** UnknownMetric or NoDirection when the rule names a goal that cannot be answered, else null. */
        public ?GoalState $problem = null,
    ) {
    }
}
