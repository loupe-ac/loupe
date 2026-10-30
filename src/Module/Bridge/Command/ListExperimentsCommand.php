<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;

final readonly class ListExperimentsCommand
{
    public function __construct(
        public Project $project,
        /** True lists the archived experiments, false the others. */
        public bool $archived = false,
    ) {
    }
}
