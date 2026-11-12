<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Service\InitInstallWaits;

/**
 * Keeps only the named repositories that the project holds, so the page never shows a name the owner did not connect.
 * It returns none once the init session no longer waits, so a stale visit never claims that init sees the install.
 */
final readonly class ShowInitHandoffHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private InitInstallWaits $installWaits,
    ) {
    }

    /** @return list<string> the paths in the order the link named them */
    public function __invoke(ShowInitHandoffCommand $command): array
    {
        $projectId = $command->project->id ?? throw new \LogicException('A stored project has an id.');
        if (!$this->installWaits->isWaiting($command->init, (string) $projectId)) {
            return [];
        }

        $held = array_map(
            static fn ($repository): string => $repository->path,
            $this->forgeRepositories->findByProjectId($projectId),
        );

        return array_values(array_unique(array_filter(
            explode(',', $command->repositories),
            static fn (string $path): bool => \in_array($path, $held, true),
        )));
    }
}
