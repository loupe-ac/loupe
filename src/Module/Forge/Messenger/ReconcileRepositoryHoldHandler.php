<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ReconcileRepositoryHoldHandler
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReconcileRepositoryHold $message): void
    {
        $this->events->dispatch(new ForgeRepositoryCheckChanged(Uuid::fromString($message->projectId)));
    }
}
