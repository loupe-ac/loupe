<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** A metric that the experiment must not worsen. Each comparison reads its variant against the baseline value. */
final readonly class ExperimentGuard
{
    public function __construct(
        public ExperimentMetric $metric,
        /** Null when the experiment has no baseline, or the baseline has no data. */
        public ?float $baselinePoint,
    ) {
    }
}
