<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\WorkflowFileReads;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettings;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues the read of `.loupe.yaml` for a repository row. */
final readonly class RepositoryWorkflowReads implements WorkflowFileReads
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function queue(ForgeRepository $repository, bool $force = false): void
    {
        $this->bus->dispatch(new ReadRepositoryWorkflowSettings((string) ($repository->id ?? throw new \LogicException('A persisted repository has an id.')), $force));
    }
}
