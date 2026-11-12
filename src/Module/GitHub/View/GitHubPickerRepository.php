<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\GitHub\GitHubRepositoryRef;

final readonly class GitHubPickerRepository
{
    public function __construct(
        public GitHubRepositoryRef $repository,
        public GitHubPickerState $state,
        /** The name of the project that holds it, set only when the owner may open that project. */
        public ?string $holderName = null,
        public bool $ticked = false,
    ) {
    }

    /** The value a tickbox posts. */
    public function pick(int $installationId): string
    {
        return $installationId.':'.$this->repository->id.':'.$this->repository->fullName;
    }
}
