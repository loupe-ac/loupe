<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Project\Entity\Project;

/**
 * Answers whether the agent session of the current call belongs to work that Loupe withdrew.
 * The AgentReview module implements it.
 */
interface WithdrawnWork
{
    /** True when the call comes from a worker run whose work request is cancelled or expired. A call with no worker run is never withdrawn. */
    public function currentCallIsWithdrawn(Project $project): bool;
}
