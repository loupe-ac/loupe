<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Event\ResumableWorkerRunEnded;
use App\Module\Bridge\Messenger\ResumeWaitingSession;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A wait that ended while its run was live got no resume, because a live run takes none. The end of the run queues that resume.
 */
#[AsEventListener]
final readonly class ResumeWaitingSessionOnResumableWorkerRunEnded
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ResumableWorkerRunEnded $event): void
    {
        foreach ($this->workerWaits->findEndedWithoutResumeOfSession($event->bridgeId, $event->sessionId) as $wait) {
            $this->bus->dispatch(new ResumeWaitingSession($wait->id?->toRfc4122() ?? throw new \LogicException('A stored wait has an id.')));
        }
    }
}
