<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** A verified delivery says a branch of a repository that one project owns was deleted. */
final readonly class ForgeBranchDeleted
{
    public function __construct(
        public Uuid $projectId,
        public string $forge,
        public string $repository,
        public string $branch,
    ) {
    }
}
