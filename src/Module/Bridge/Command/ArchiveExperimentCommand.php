<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;

final readonly class ArchiveExperimentCommand
{
    public function __construct(
        public Project $project,
        public string $experiment,
    ) {
    }
}
