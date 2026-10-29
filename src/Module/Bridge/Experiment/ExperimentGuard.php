<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** A metric that the experiment must not worsen. It compares the second variant against the first. */
final readonly class ExperimentGuard
{
    public function __construct(
        public ExperimentMetric $metric,
        /**
         * @var list<ExperimentArm>
         */
        public array $arms,
        /** Signed difference of the second variant minus the first. In ratio points between -1 and 1 for a ratio. Null when a variant has no data. */
        public ?Interval $change,
    ) {
    }
}
