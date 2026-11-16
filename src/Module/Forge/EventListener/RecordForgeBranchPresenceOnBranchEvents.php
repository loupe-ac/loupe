<?php

declare(strict_types=1);

namespace App\Module\Forge\EventListener;

use App\Module\Forge\Event\ForgeBranchDeleted;
use App\Module\Forge\Event\ForgeBranchPresenceChanged;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\Messenger\CheckForgeBranch;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A push writes the stored answer of a branch that an evaluation already asked for, and queues a check that
 * confirms it. A branch that nobody asked for gets no write. A repository the project cannot read through
 * its forge access gets none either, because a signed push can also come from a plain repository webhook.
 */
final readonly class RecordForgeBranchPresenceOnBranchEvents
{
    public function __construct(
        private ProjectRepository $projects,
        private BranchReaders $branchReaders,
        private ForgeBranchPresence $presence,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener]
    public function onPushed(ForgeBranchPushed $event): void
    {
        $this->record($event->projectId, $event->forge, $event->repository, $event->branch, true);
    }

    #[AsEventListener]
    public function onDeleted(ForgeBranchDeleted $event): void
    {
        $this->record($event->projectId, $event->forge, $event->repository, $event->branch, false);
    }

    private function record(Uuid $projectId, string $forge, string $path, string $branch, bool $exists): void
    {
        $receivedAt = $this->clock->now();
        $project = $this->projects->find($projectId);
        $row = null === $project ? null : $this->branchReaders->readableRepositoryAt($project, $forge, $path);
        if (null === $project || null === $row || !$this->presence->tracks($row, $branch)) {
            return;
        }

        $changed = $this->presence->record($row, $branch, $exists, $receivedAt);
        $this->bus->dispatch(new CheckForgeBranch((string) $projectId, (string) $row->id, $branch));
        if ($changed) {
            $this->events->dispatch(new ForgeBranchPresenceChanged($projectId, $forge, $path, $branch, $exists));
        }
    }
}
