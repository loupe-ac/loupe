<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class ListSavedGraphsCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
