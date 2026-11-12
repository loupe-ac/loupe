<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentListPosition;
use App\Module\Project\Entity\Project;

final readonly class ListExperimentsCommand
{
    public function __construct(
        public Project $project,
        /** True lists the archived experiments, false the others. */
        public bool $archived = false,
        /** Walks the list by position with `limit` rows. Null starts at the first row. */
        public ?ExperimentListPosition $after = null,
        /** The most rows of the list to return. Null returns them all. */
        public ?int $limit = null,
    ) {
    }
}
