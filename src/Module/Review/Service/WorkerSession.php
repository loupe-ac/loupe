<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Project\Entity\Project;

/**
 * Answers whether the agent session of the current call is a session that the bridge started as a worker.
 * The AgentReview module implements it.
 */
interface WorkerSession
{
    /** True when the call carries the session of a worker run of the project. A call with no session is never a worker call. */
    public function currentCallIsWorker(Project $project): bool;
}
