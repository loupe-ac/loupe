<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Service\InitInstallWaits;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An init run closed, so its wait for the App install is over. A waiting init that still lives writes the mark again with its next renewal.
 */
#[AsEventListener]
final readonly class ClearInitInstallWaitOnWorkerRunChanged
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private InitInstallWaits $installWaits,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
            if (InitRuns::WORK_KIND === $run->workKind && WorkSubject::PROJECT === $run->subjectType && !$run->state->isOpen() && null !== $run->sessionId) {
                $this->installWaits->clear($run->sessionId->toRfc4122());
            }
        }
    }
}
