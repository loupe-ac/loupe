<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;

final readonly class TryAccountNowCommand
{
    public function __construct(
        public Project $project,
        public Bridge $bridge,
        public string $account,
        public User $requestedBy,
    ) {
    }
}
