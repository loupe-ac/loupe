<?php

declare(strict_types=1);

namespace App\Module\Readiness\Messenger;

use App\Module\Readiness\Command\OpenDiscoveryRevisionCommand;
use App\Module\Readiness\Command\OpenDiscoveryRevisionHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class RequestDiscoveryRevisionHandler
{
    public function __construct(
        private OpenDiscoveryRevisionHandler $openDiscoveryRevision,
    ) {
    }

    public function __invoke(RequestDiscoveryRevision $message): void
    {
        ($this->openDiscoveryRevision)(new OpenDiscoveryRevisionCommand(
            Uuid::fromString($message->runId),
            null === $message->previousWorkRequestId ? null : Uuid::fromString($message->previousWorkRequestId),
        ));
    }
}
