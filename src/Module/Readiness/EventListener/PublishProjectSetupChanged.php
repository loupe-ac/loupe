<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Forge\Event\ForgeRepositoryJoined;
use App\Module\Forge\Event\ForgeRepositoryLeft;
use App\Module\Project\Contract\Event\ProjectMilestoneReached;
use App\Module\Project\Contract\Event\ProjectSetUp;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Service\ProjectSetupPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Signals the open setup pages when a fact they list changes. */
final readonly class PublishProjectSetupChanged
{
    public function __construct(
        private ProjectSetupPublisher $publisher,
        private ProjectRepository $projects,
    ) {
    }

    #[AsEventListener]
    public function onRepositoryJoined(ForgeRepositoryJoined $event): void
    {
        $this->publisher->changed($event->projectId);
    }

    #[AsEventListener]
    public function onRepositoryLeft(ForgeRepositoryLeft $event): void
    {
        $this->publisher->changed($event->projectId);
    }

    #[AsEventListener]
    public function onMilestoneReached(ProjectMilestoneReached $event): void
    {
        $this->publisher->changed($event->projectId);
    }

    #[AsEventListener]
    public function onProjectSetUp(ProjectSetUp $event): void
    {
        $this->publisher->changed($event->projectId);
    }

    /** An init run opens and closes through this event. A project that is set up shows no run, so it signals nothing. */
    #[AsEventListener]
    public function onWorkerRunChanged(WorkerRunChanged $event): void
    {
        if (false === $this->projects->find($event->projectId)?->setUp) {
            $this->publisher->changed($event->projectId);
        }
    }
}
