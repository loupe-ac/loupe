<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class AddedGitHubRepositories
{
    /**
     * @param list<string> $added    the paths now in the project
     * @param list<string> $refused  the paths that GitHub no longer shows to the owner, or that another project holds
     * @param list<string> $readOnly the paths that the signed-in GitHub user cannot push to
     */
    public function __construct(
        public array $added,
        public array $refused,
        public array $readOnly,
    ) {
    }
}
