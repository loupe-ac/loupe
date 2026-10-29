<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One guard as one variant shows it: its value, and its change against the baseline. */
final readonly class ExperimentGuardChange
{
    public function __construct(
        public string $key,
        /** Null when the variant has no data. */
        public ?float $point,
        /** Variant minus baseline. In ratio points between -1 and 1 for a ratio. Null when either arm has no data. */
        public ?Interval $change,
    ) {
    }
}
