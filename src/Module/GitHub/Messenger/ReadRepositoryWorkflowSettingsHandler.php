<?php

declare(strict_types=1);

namespace App\Module\GitHub\Messenger;

use App\Module\GitHub\Command\ReadWorkflowFileCommand;
use App\Module\GitHub\Command\ReadWorkflowFileHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ReadRepositoryWorkflowSettingsHandler
{
    public function __construct(
        private ReadWorkflowFileHandler $readWorkflowFile,
    ) {
    }

    public function __invoke(ReadRepositoryWorkflowSettings $message): void
    {
        if (!Uuid::isValid($message->repositoryId)) {
            return;
        }

        ($this->readWorkflowFile)(new ReadWorkflowFileCommand(Uuid::fromString($message->repositoryId), $message->force, $message->attempt));
    }
}
