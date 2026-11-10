<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\GitHub\Service\GitHubUserInstallation;

/** One GitHub account of the picker, with the repositories of its installation that the owner reaches. */
final readonly class GitHubPickerAccount
{
    /**
     * @param list<GitHubPickerRepository> $repositories
     * @param bool                         $complete     false when GitHub may hold more repositories than the list shows
     */
    public function __construct(
        public GitHubUserInstallation $installation,
        public array $repositories,
        public bool $complete,
        public ?string $adjustUrl,
    ) {
    }

    /** @return list<GitHubPickerRepository> */
    public function free(): array
    {
        return array_values(array_filter($this->repositories, static fn (GitHubPickerRepository $repository): bool => GitHubPickerState::Free === $repository->state));
    }
}
