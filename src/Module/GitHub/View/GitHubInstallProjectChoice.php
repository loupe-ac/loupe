<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** What the project choice page shows after an install that began on github.com. */
final readonly class GitHubInstallProjectChoice
{
    /** @param list<GitHubInstallProjectOption> $projects the projects the owner owns, newest first */
    public function __construct(
        public string $accountLogin,
        public array $projects,
    ) {
    }

    /** @return array<string, GitHubInstallProjectOption> the options by project id */
    public function optionsById(): array
    {
        $byId = [];
        foreach ($this->projects as $option) {
            $byId[(string) $option->project->id] = $option;
        }

        return $byId;
    }
}
