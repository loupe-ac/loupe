<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the commit that added a repository to a project. */
final readonly class ForgeRepositoryJoined
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
