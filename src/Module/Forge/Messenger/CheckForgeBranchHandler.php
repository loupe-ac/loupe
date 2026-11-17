<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

use App\Module\Forge\Event\ForgeBranchPresenceChanged;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CheckForgeBranchHandler
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private BranchReaders $branchReaders,
        private ForgeBranchPresence $presence,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckForgeBranch $message): void
    {
        $seenAt = $this->clock->now();
        $repository = Uuid::isValid($message->repositoryId) ? $this->forgeRepositories->find($message->repositoryId) : null;
        $project = $repository?->project;
        if (null === $repository || null === $project || (string) $project->id !== $message->projectId) {
            return;
        }

        $readable = $this->branchReaders->readableRepositoryAt($project, $repository->forge, $repository->path);
        if (null === $readable || !$readable->id?->equals($repository->id)) {
            return;
        }

        $answer = $this->branchReaders->presence($project, $repository, $message->branch);
        if (BranchPresence::Unreadable === $answer) {
            return;
        }

        $exists = BranchPresence::Exists === $answer;
        if ($this->presence->record($repository, $message->branch, $exists, $seenAt)) {
            $this->events->dispatch(new ForgeBranchPresenceChanged($project->requireId(), $repository->forge, $repository->path, $message->branch, $exists));
        }
    }
}
