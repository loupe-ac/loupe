<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\Project\Entity\Project;

/** One project the owner may pick on the project choice page, with the repositories it holds now. */
final readonly class GitHubInstallProjectOption
{
    public function __construct(
        public Project $project,
        public int $repositoryCount,
    ) {
    }
}
