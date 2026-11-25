<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

final readonly class CancelDiscoveryCommand
{
    public function __construct(
        public Project $project,
        public User $user,
    ) {
    }
}
