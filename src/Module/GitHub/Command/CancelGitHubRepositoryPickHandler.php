<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\GitHubInstallSession;
use Psr\Log\LoggerInterface;

final readonly class CancelGitHubRepositoryPickHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
        private LoggerInterface $logger,
    ) {
    }

    /** Removes the owner's GitHub token and the rest of the picker session. */
    public function __invoke(CancelGitHubRepositoryPickCommand $command): void
    {
        $this->installSession->clear();
        $this->logger->info('github.picker_cancelled', ['projectId' => (string) $command->project->id]);
    }
}
