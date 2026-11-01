<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Messenger;

use App\Module\OpenRouter\Command\ReadBilledCostCommand;
use App\Module\OpenRouter\Command\ReadBilledCostHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ReadBilledCostMessageHandler
{
    public function __construct(
        private ReadBilledCostHandler $readBilledCost,
    ) {
    }

    public function __invoke(ReadBilledCost $message): void
    {
        if (!Uuid::isValid($message->runId)) {
            return;
        }

        ($this->readBilledCost)(new ReadBilledCostCommand(Uuid::fromString($message->runId)));
    }
}
