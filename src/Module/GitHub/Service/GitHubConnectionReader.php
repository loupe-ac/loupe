<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\ForgeConnectionReader;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;

/** GitHub has no nested path: a repository is an owner and a name. */
final readonly class GitHubConnectionReader implements ForgeConnectionReader
{
    private const string PATH = '~^(?!\.{1,2}/)[A-Za-z0-9_.-]+/(?!\.{1,2}$)[A-Za-z0-9_.-]+$~D';

    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositoryRepository $forgeRepositories,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function acceptsPath(string $path): bool
    {
        return 1 === preg_match(self::PATH, $path);
    }

    #[\Override]
    public function isConnected(Project $project): bool
    {
        foreach ($this->gitHubInstallations->findByProject($project) as $installation) {
            if (null === $installation->removedAt) {
                return true;
            }
        }

        return array_any($this->forgeRepositories->findByProject($project), fn ($repository) => GitHubDelivery::FORGE === $repository->forge);
    }

    #[\Override]
    public function pullRequestUrl(string $path, int $number): string
    {
        if (!$this->acceptsPath($path)) {
            throw new \InvalidArgumentException(sprintf('GitHub has no repository at "%s".', $path));
        }

        return 'https://github.com/'.$path.'/pull/'.$number;
    }
}
