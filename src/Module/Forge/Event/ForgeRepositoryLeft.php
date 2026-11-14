<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the commit that removed a repository from a project. */
final readonly class ForgeRepositoryLeft
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
