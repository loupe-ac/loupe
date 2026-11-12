<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\Project\Entity\Project;

final readonly class GitHubRepositoryPickerView
{
    /**
     * @param list<GitHubPickerAccount> $accounts
     * @param ?string                   $missingRepository   the path the owner asked to tick when no installation lists it
     * @param list<GitHubInstallation>  $hiddenInstallations the installations of the project that the owner's GitHub user cannot see
     */
    public function __construct(
        public Project $project,
        public array $accounts,
        public ?string $missingRepository = null,
        public array $hiddenInstallations = [],
    ) {
    }

    public function hasFreeRepository(): bool
    {
        return array_any($this->accounts, fn ($account) => [] !== $account->free());
    }
}
