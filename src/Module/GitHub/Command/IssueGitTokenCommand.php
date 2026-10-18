<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Account\Entity\User;

final readonly class IssueGitTokenCommand
{
    public function __construct(
        public User $owner,
        /** An id or a slug. */
        public string $handle,
        /** The `owner/name` of the repository the worker pushes to. */
        public string $repository,
    ) {
    }
}
