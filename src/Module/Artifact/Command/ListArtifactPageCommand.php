<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Project\Entity\Project;

final readonly class ListArtifactPageCommand
{
    public function __construct(
        public Project $project,
        public int $page,
        public int $perPage,
        public bool $includeArchived,
    ) {
    }
}
