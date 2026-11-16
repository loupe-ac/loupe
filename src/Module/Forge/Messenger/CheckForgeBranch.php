<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/** Asks the forge once whether one branch of one repository exists, and stores the answer. */
final readonly class CheckForgeBranch
{
    public function __construct(
        public string $projectId,
        public string $repositoryId,
        public string $branch,
    ) {
    }
}
