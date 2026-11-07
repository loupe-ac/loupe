<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\ExperimentComparison;

/** One variant of a result block: its comparison, the tone of its change and its strip. */
final readonly class ExperimentResultRow
{
    public function __construct(
        public ExperimentComparison $comparison,
        public ExperimentResultTone $tone,
        /** Null when the comparison has no change. */
        public ?ExperimentResultStrip $strip,
    ) {
    }
}
