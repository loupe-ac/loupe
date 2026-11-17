<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

final readonly class GitHubRepositoryList
{
    /**
     * @param list<GitHubListedRepository> $repositories
     * @param bool                         $complete     false when GitHub may hold more pages than were read
     */
    public function __construct(
        public array $repositories,
        public bool $complete,
    ) {
    }
}
