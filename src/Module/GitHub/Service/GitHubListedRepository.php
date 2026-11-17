<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\GitHubRepositoryRef;

/** One repository of a user listing, with whether that user can push to it. */
final readonly class GitHubListedRepository
{
    public function __construct(
        public GitHubRepositoryRef $repository,
        public bool $canPush,
    ) {
    }
}
