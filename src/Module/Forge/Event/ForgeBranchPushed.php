<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** A verified delivery says a commit reached a branch of a repository that one project owns. A branch deletion is not a push. */
final readonly class ForgeBranchPushed
{
    public function __construct(
        public Uuid $projectId,
        public string $forge,
        public string $repository,
        public string $branch,
    ) {
    }
}
