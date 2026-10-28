<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Repository\GitHubInstallationRepository;

/** Finds the App installation behind a repository row of a project. */
final readonly class GitHubRepositoryInstallations
{
    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
    ) {
    }

    /**
     * @return array{int, string} the installation id, and the repository path as the forge spells it
     *
     * @throws GitHubInstallationUnavailable
     */
    public function for(ForgeRepository $repository): array
    {
        $sourceRef = $repository->sourceRef;
        if (ForgeRepositorySource::Installation !== $repository->source || null === $sourceRef || !ctype_digit($sourceRef)) {
            throw new GitHubInstallationUnavailable('no_installation');
        }

        $installation = $this->gitHubInstallations->findOneByInstallationId((int) $sourceRef);
        if (null === $installation || null !== $installation->removedAt || !$installation->project->id?->equals($repository->project->id)) {
            throw new GitHubInstallationUnavailable('no_installation');
        }
        if (null !== $installation->suspendedAt) {
            throw new GitHubInstallationUnavailable('installation_suspended');
        }

        return [$installation->installationId, $repository->path];
    }
}
