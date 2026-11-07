<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;

/**
 * An experiment as a visitor sees it, ready to keep. It holds the totals of the report and nothing else: no card,
 * no project and no entity, so it serializes small and carries no name of a person.
 */
final readonly class PublicExperimentResult
{
    /**
     * @param list<ExperimentVariant>         $variants
     * @param array<string, ExperimentMetric> $metrics  key => metric, in display order
     */
    public function __construct(
        /** The clock reading the report was computed at. */
        public \DateTimeImmutable $computedAt,
        public string $experiment,
        public ?ExperimentHeadline $headline,
        public array $variants,
        public array $metrics,
    ) {
    }
}
