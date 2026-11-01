<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

/** Removes the key of a user, or the key of one project. */
final readonly class RemoveOpenRouterKeyCommand
{
    public function __construct(
        public User|Project $owner,
    ) {
    }
}
