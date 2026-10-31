<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;

/** A file of its own, because a billed cost row outlives the run that WorkerRunExporter writes. */
final readonly class WorkerRunBilledCostExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunBilledCostRepository $workerRunBilledCosts,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_billed_costs.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunBilledCosts->findByOwner($user) as $row) {
            yield [
                'runId' => $row->runId->toRfc4122(),
                'runKey' => $row->runKey->toRfc4122(),
                'project' => $row->project->name,
                'subjectType' => $row->subjectType,
                'subjectId' => $row->subjectId->toRfc4122(),
                'runStartedAt' => $row->runStartedAt->format(\DateTimeInterface::ATOM),
                'state' => $row->state->value,
                'reason' => $row->reason?->value,
                'costUsd' => $row->costUsd,
                'attempts' => $row->attempts,
                'updatedAt' => $row->updatedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
