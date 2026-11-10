<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\Entity\GitHubRepositorySelection;

/** An App installation as GitHub lists it for a signed-in user. */
final readonly class GitHubUserInstallation
{
    public function __construct(
        public int $id,
        public string $accountLogin,
        public GitHubRepositorySelection $repositorySelection,
        public ?int $accountId = null,
        /** The settings page of the installation on GitHub. */
        public ?string $htmlUrl = null,
    ) {
    }
}
