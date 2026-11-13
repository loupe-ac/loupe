<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\WorkflowFileReadQueues;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An init run closed, so the onboarding items it held back follow the repositories again. Each repository that
 * still has access gets a forced read of `.loupe.yaml` and a fresh check. A repository that lost its access
 * gets neither, because the forge refuses both.
 */
#[AsEventListener]
final readonly class QueueInitEndedReadsOnWorkerRunChanged
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ForgeRepositoryRepository $forgeRepositories,
        private WorkflowFileReadQueues $reads,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $ended = false;
        foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
            if (InitRuns::WORK_KIND === $run->workKind && WorkSubject::PROJECT === $run->subjectType && !$run->state->isOpen()) {
                $ended = true;
            }
        }
        if (!$ended) {
            return;
        }

        foreach ($this->forgeRepositories->findByProjectId($event->projectId) as $repository) {
            if (ForgeRepositorySource::Installation !== $repository->source || null !== $repository->accessLostAt) {
                continue;
            }

            $this->reads->for($repository->forge)?->queue($repository, true);
            $this->bus->dispatch(new CheckForgeRepository((string) ($repository->id ?? throw new \LogicException('A stored repository has an id.')), CheckTrigger::InitEnded));
        }
    }
}
