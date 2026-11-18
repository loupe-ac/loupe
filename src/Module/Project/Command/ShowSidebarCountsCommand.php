<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

final readonly class ShowSidebarCountsCommand
{
    /** @param ?string $since the versions that the page rendered, as "<agents>.<inbox>" */
    public function __construct(
        public Project $project,
        public User $viewer,
        public ?string $since,
    ) {
    }
}
