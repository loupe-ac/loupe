<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettings;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues the read of `.loupe.yaml` for a repository row. */
final readonly class RepositoryWorkflowReads
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function queue(ForgeRepository $repository): void
    {
        $this->bus->dispatch(new ReadRepositoryWorkflowSettings((string) ($repository->id ?? throw new \LogicException('A persisted repository has an id.'))));
    }
}
