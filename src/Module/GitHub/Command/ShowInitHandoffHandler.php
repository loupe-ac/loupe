<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Repository\ForgeRepositoryRepository;

/** Keeps only the named repositories that the project holds, so the page never shows a name the owner did not connect. */
final readonly class ShowInitHandoffHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
    ) {
    }

    /** @return list<string> the paths in the order the link named them */
    public function __invoke(ShowInitHandoffCommand $command): array
    {
        $held = array_map(
            static fn ($repository): string => $repository->path,
            $this->forgeRepositories->findByProjectId($command->project->id ?? throw new \LogicException('A stored project has an id.')),
        );

        return array_values(array_unique(array_filter(
            explode(',', $command->repositories),
            static fn (string $path): bool => \in_array($path, $held, true),
        )));
    }
}
