<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

use App\Module\Forge\Command\RunRepositoryCheckCommand;
use App\Module\Forge\Command\RunRepositoryCheckHandler;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CheckForgeRepositoryHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private RunRepositoryCheckHandler $runRepositoryCheck,
    ) {
    }

    public function __invoke(CheckForgeRepository $message): void
    {
        $repository = Uuid::isValid($message->repositoryId) ? $this->forgeRepositories->find($message->repositoryId) : null;
        if (null === $repository) {
            return;
        }

        ($this->runRepositoryCheck)(new RunRepositoryCheckCommand($repository, $message->trigger));
    }
}
