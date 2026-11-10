<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\Project\Entity\Project;

final readonly class GitHubRepositoryPickerView
{
    /**
     * @param list<GitHubPickerAccount> $accounts
     * @param ?string                   $missingRepository the path the owner asked to tick when no installation lists it
     */
    public function __construct(
        public Project $project,
        public array $accounts,
        public ?string $missingRepository = null,
    ) {
    }

    public function hasFreeRepository(): bool
    {
        return array_any($this->accounts, fn ($account) => [] !== $account->free());
    }
}
