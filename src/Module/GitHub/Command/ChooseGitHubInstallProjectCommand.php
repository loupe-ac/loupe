<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Account\Entity\User;

final readonly class ChooseGitHubInstallProjectCommand
{
    public function __construct(
        public User $owner,
        /** The id of an owned project, or null to create a new one. */
        public ?string $projectId,
        /** The name of the new project. Only a new project reads it. */
        public ?string $name,
    ) {
    }
}
