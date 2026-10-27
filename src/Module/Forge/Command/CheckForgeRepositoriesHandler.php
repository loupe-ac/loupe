<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Workflow\Contract\RepositorySuggestions;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Queues an hourly check for each repository of a project whose template has suggestions. */
final readonly class CheckForgeRepositoriesHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private RepositorySuggestions $suggestions,
        private MessageBusInterface $bus,
    ) {
    }

    /** @return int how many checks it queued */
    public function __invoke(CheckForgeRepositoriesCommand $command): int
    {
        $hasSuggestions = [];
        $queued = 0;
        foreach ($this->forgeRepositories->findIdsWithProject() as $row) {
            $hasSuggestions[$row['projectId']] ??= [] !== $this->suggestions->forProject(Uuid::fromString($row['projectId']));
            if ($hasSuggestions[$row['projectId']]) {
                $this->bus->dispatch(new CheckForgeRepository($row['id'], CheckTrigger::Hourly));
                ++$queued;
            }
        }

        return $queued;
    }
}
