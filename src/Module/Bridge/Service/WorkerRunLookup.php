<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;

/** Finds a worker run inside one project, so the id of a run of another project reads as a missing one. */
final readonly class WorkerRunLookup
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function find(Project $project, string $runId): ?WorkerRun
    {
        return $this->workerRuns->findOneByIdAndProjectId($runId, (string) $project->id);
    }
}
