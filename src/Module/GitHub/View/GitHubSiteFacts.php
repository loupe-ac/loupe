<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** The GitHub facts of one project. */
final readonly class GitHubSiteFacts
{
    /**
     * @param list<GitHubRepositoryFacts> $repositories
     */
    public function __construct(
        public bool $appInstalled,
        public array $repositories,
    ) {
    }
}
