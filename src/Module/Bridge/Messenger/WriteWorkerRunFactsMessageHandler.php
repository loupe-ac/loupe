<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Command\WriteWorkerRunFactsCommand;
use App\Module\Bridge\Command\WriteWorkerRunFactsHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class WriteWorkerRunFactsMessageHandler
{
    public function __construct(
        private WriteWorkerRunFactsHandler $write,
    ) {
    }

    public function __invoke(WriteWorkerRunFacts $message): void
    {
        ($this->write)(new WriteWorkerRunFactsCommand(
            Uuid::fromString($message->projectId),
            array_map(Uuid::fromString(...), $message->runIds),
        ));
    }
}
