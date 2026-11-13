<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** A verified delivery says a pull request from a branch of a repository that one project owns opened, closed, reopened or got a commit. */
final readonly class ForgePullRequestBranchChanged
{
    public function __construct(
        public Uuid $projectId,
        public string $forge,
        public string $repository,
        public string $branch,
    ) {
    }
}
