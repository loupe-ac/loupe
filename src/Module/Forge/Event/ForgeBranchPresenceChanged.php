<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** The stored answer about whether a branch exists changed. */
final readonly class ForgeBranchPresenceChanged
{
    public function __construct(
        public Uuid $projectId,
        public string $forge,
        public string $repository,
        public string $branch,
        public bool $exists,
    ) {
    }
}
