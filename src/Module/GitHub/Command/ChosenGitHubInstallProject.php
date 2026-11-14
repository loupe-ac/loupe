<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;

final readonly class ChosenGitHubInstallProject
{
    public function __construct(
        public Project $project,
        /** The path that the install added at once, or null when the picker opens. */
        public ?string $connected,
        /** @var list<string> the paths that another project took first */
        public array $refused = [],
    ) {
    }
}
