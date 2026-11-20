<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Event\WorkerRunFactsWritten;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\ValueObject\WorkerRunChangeKind;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class WriteWorkerRunFactsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunFactWriter $writer,
        private WorkerRunChangedPublisher $publisher,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(WriteWorkerRunFactsCommand $command): void
    {
        $project = $this->projects->find($command->projectId);
        if (null === $project) {
            return;
        }

        // A run writer locks the project before the run, so this job does too.
        $changed = $this->em->wrapInTransaction(function () use ($project, $command): int {
            $this->projects->lockForReport($project);

            return $this->writer->upsert($command->runIds);
        });

        $this->events->dispatch(new WorkerRunFactsWritten($command->projectId, array_map(static fn (Uuid $id): string => $id->toRfc4122(), $command->runIds)));
        if ($changed > 0) {
            $cardIds = [];
            foreach ($this->workerRuns->findByIds(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $command->runIds)) as $run) {
                if (null !== $run->cardId()) {
                    $cardIds[(string) $run->cardId()] = $run->cardId();
                }
            }
            $this->publisher->runsChanged($project, WorkerRunChangeKind::State, cardIds: array_values($cardIds), runIds: $command->runIds);
        }
    }
}
