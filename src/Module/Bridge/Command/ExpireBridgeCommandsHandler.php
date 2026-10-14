<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Event\BridgeCommandSettled;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Answers how many commands expired. One event for each command, after the update. */
final readonly class ExpireBridgeCommandsHandler
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private WorkerRunChangedPublisher $runsChanged,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireBridgeCommandsCommand $command): int
    {
        // One instant for the read and the update, so the read names every project the update touches.
        $now = $this->clock->now();
        $due = $this->bridgeCommands->findDue($now);
        $expired = $this->bridgeCommands->expireDue($now);

        $projects = [];
        foreach ($due as $command) {
            $projects[(string) $command->project->id] = $command->project;
        }
        foreach ($projects as $project) {
            $this->runsChanged->runsChanged($project);
        }
        foreach ($due as $command) {
            $this->events->dispatch(BridgeCommandSettled::of($command));
        }

        return $expired;
    }
}
