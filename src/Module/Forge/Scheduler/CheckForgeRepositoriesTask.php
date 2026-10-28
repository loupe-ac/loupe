<?php

declare(strict_types=1);

namespace App\Module\Forge\Scheduler;

use App\Module\Forge\Command\CheckForgeRepositoriesCommand;
use App\Module\Forge\Command\CheckForgeRepositoriesHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Queues an hourly check of each repository that a template with suggestions covers. */
#[AsCronTask('%app.forge.repository_check_schedule%')]
final readonly class CheckForgeRepositoriesTask
{
    public function __construct(
        private CheckForgeRepositoriesHandler $checkForgeRepositories,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $queued = ($this->checkForgeRepositories)(new CheckForgeRepositoriesCommand());

        if ($queued > 0) {
            $this->logger->info('forge.repositories_check_queued', ['queued' => $queued]);
        }
    }
}
