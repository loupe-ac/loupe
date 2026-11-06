<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

final readonly class ExcludeExperimentCardCommand
{
    public function __construct(
        public Project $project,
        public string $experiment,
        public int $number,
        /** @var non-empty-string */
        public string $reason,
        /** Null for a credential that no account owns. */
        public ?User $user,
    ) {
    }
}
