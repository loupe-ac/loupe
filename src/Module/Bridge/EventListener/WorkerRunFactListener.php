<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Messenger\WriteWorkerRunFacts;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rewrites the fact row of each run a flush inserts or changes, and of each
 * run that gets new usage rows. Inside wrapInTransaction the rewrite joins the
 * caller's transaction. A flush that throws never reaches postFlush, so a
 * clear or a service reset drops the runs it collected. Inside
 * deferredTransaction the runs go to one queued message instead.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class WorkerRunFactListener implements ResetInterface
{
    /** @var array<int, WorkerRun> by object id */
    public private(set) array $runs = [];

    /** @var array<string, array<string, true>> run ids by project id, both RFC 4122 */
    private array $pending = [];

    private bool $writing = false;

    private bool $deferred = false;

    public function __construct(
        private readonly WorkerRunFactWriter $writer,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof WorkerRun) {
                $this->runs[spl_object_id($entity)] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof WorkerRunUsage && null !== $entity->run) {
                $this->runs[spl_object_id($entity->run)] = $entity->run;
            }
        }
    }

    /** A flush during the write adds runs to the list, and the loop writes them too. */
    public function postFlush(): void
    {
        if ($this->deferred) {
            $this->collect();

            return;
        }

        if ($this->writing) {
            return;
        }

        $this->writing = true;
        try {
            while ([] !== $this->runs) {
                $runs = $this->runs;
                $this->runs = [];
                $this->writer->upsert(array_values(array_filter(
                    array_map(static fn (WorkerRun $run): ?Uuid => $run->id, $runs),
                )));
            }
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Runs the work in a transaction that queues one write of the fact rows of
     * every run it flushed or named, in place of writing them. The message
     * commits with the transaction, and a rollback queues nothing.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function deferredTransaction(EntityManagerInterface $em, callable $work): mixed
    {
        $this->deferred = true;
        try {
            return $em->wrapInTransaction(function () use ($work): mixed {
                $result = $work();
                $this->collect();
                foreach ($this->pending as $projectId => $runIds) {
                    $this->bus->dispatch(new WriteWorkerRunFacts($projectId, array_keys($runIds)));
                }

                return $result;
            });
        } finally {
            $this->deferred = false;
            $this->pending = [];
            $this->runs = [];
        }
    }

    /** Names a run whose fact row the deferred transaction must write, though it did not flush the run. */
    public function addRun(WorkerRun $run): void
    {
        $this->runs[spl_object_id($run)] = $run;
    }

    public function onClear(): void
    {
        $this->reset();
    }

    #[\Override]
    public function reset(): void
    {
        $this->runs = [];
        $this->pending = [];
        $this->writing = false;
        $this->deferred = false;
    }

    private function collect(): void
    {
        foreach ($this->runs as $run) {
            if (null !== $run->id) {
                $this->pending[$run->project->requireId()->toRfc4122()][$run->id->toRfc4122()] = true;
            }
        }
        $this->runs = [];
    }
}
