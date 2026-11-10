<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;

final readonly class StartGitHubRepositoryPickCommand
{
    public function __construct(
        public Project $project,
        /** The path to tick in the picker, as the caller sent it. */
        public ?string $repository,
        public string $redirectUri,
    ) {
    }
}
