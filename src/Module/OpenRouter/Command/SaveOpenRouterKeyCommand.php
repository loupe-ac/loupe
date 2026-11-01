<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

/** Stores a management key for a user, or for one project. */
final readonly class SaveOpenRouterKeyCommand
{
    public function __construct(
        public User|Project $owner,
        public string $secret,
    ) {
    }
}
