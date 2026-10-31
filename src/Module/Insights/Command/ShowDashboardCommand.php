<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowDashboardCommand
{
    public function __construct(
        public Project $project,
        /** The id from the `removed` query parameter, or null. Any text is safe. */
        public ?string $removedGraphId = null,
    ) {
    }
}
