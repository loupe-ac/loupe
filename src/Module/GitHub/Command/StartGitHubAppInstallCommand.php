<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;

final readonly class StartGitHubAppInstallCommand
{
    public function __construct(
        public Project $project,
        /** The path to tick in the picker when the install returns. */
        public ?string $repository = null,
        public ?string $startedFrom = null,
        public ?string $init = null,
    ) {
    }
}
