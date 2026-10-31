<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\ValueObject\BilledCostState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * The only writer of a billed cost row after the report creates it. A final
 * row never changes, so a late or repeated read cannot overwrite it. The fact
 * row of the run follows while the run exists.
 */
final readonly class RecordBilledCostHandler
{
    public function __construct(
        private WorkerRunBilledCostRepository $workerRunBilledCosts,
        private WorkerRunFactWriter $factWriter,
        private WorkerRunChangedPublisher $publisher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RecordBilledCostCommand $command): void
    {
        $row = $this->em->wrapInTransaction(function () use ($command): ?WorkerRunBilledCost {
            $row = $this->workerRunBilledCosts->findForUpdate($command->runId);
            if (null === $row || $row->state->isFinal()) {
                return null;
            }

            $row->state = $command->state;
            $row->reason = $command->reason;
            $row->costUsd = BilledCostState::Billed === $command->state ? $command->costUsd : null;
            $row->firstReadCost = BilledCostState::Pending === $command->state ? $command->firstReadCost : null;
            $row->attempts = $command->attempts;
            $row->updatedAt = $this->clock->now();
            $this->em->flush();
            $this->factWriter->upsert([$row->runId]);

            return $row;
        });

        if (null === $row) {
            return;
        }

        $this->publisher->runsChanged($row->project);
        if ($row->state->isFinal()) {
            $this->auditor->record(
                'bridge.billed_cost_recorded',
                AuditOutcome::Success,
                [
                    'projectId' => (string) $row->project->id,
                    'runId' => (string) $row->runId,
                    'state' => $row->state->value,
                    'reason' => $row->reason?->value,
                    'attempts' => $row->attempts,
                ],
                new AuditSubject('worker_run', (string) $row->runId),
            );
        }
    }
}
