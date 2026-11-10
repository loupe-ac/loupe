<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\GitHubRepositoryPick;
use App\Module\Project\Entity\Project;

final readonly class AddGitHubRepositoriesCommand
{
    /** @param non-empty-list<GitHubRepositoryPick> $picks */
    public function __construct(
        public Project $project,
        public array $picks,
    ) {
    }
}
