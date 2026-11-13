<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentSummary;

final readonly class ListExperimentsView
{
    /**
     * @param list<ExperimentSummary> $experiments the latest run first, and an experiment with no run last
     */
    public function __construct(
        public array $experiments,
        /** How many experiments of the project are archived, whichever list this view holds. */
        public int $archivedCount = 0,
        /** True when a walk by position has rows after the last experiment of this view. */
        public bool $hasMore = false,
    ) {
    }
}
