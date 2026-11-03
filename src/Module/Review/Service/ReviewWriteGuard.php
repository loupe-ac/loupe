<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Api\Problem\ApiProblemException;
use App\Module\Project\Entity\Project;

/** Refuses a review write that only the owner may make for themselves. */
final readonly class ReviewWriteGuard
{
    public function __construct(
        private WorkerSession $workers,
    ) {
    }

    /** @throws ApiProblemException `credential_not_allowed` when the call carries the session of a bridge worker */
    public function refuseWorker(Project $project): void
    {
        if ($this->workers->currentCallIsWorker($project)) {
            throw DocumentApiProblems::workerRefused();
        }
    }
}
