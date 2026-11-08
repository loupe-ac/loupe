<?php

declare(strict_types=1);

namespace App\Module\Insights\Event;

/** The worker or a share page kept a new result for a graph or a shared experiment. Dispatched after the row is written. */
final readonly class InsightsResultKept
{
    public function __construct(
        public string $projectId,
        /** Set for a graph, null for an experiment. */
        public ?string $graphId,
        /** Set for a shared experiment, null for a graph. */
        public ?string $experiment,
    ) {
    }
}
