<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One variant on a line of the headline: its point value and the cards that finished under it. */
final readonly class ExperimentArm
{
    public function __construct(
        public string $variant,
        /** Null when the variant has no data for the metric. */
        public ?float $point,
        public int $finishedCards,
    ) {
    }
}
