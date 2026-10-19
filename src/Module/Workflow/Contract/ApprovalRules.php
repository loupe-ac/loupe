<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Forge and Board ask this port how the approval rule of a project reads, and the workflow template of the project holds the value. */
interface ApprovalRules
{
    /** Whether an approval still covers a merge from the base that holds a conflict resolution. A project bound to no template says yes. */
    public function coversConflictResolution(Uuid $projectId): bool;
}
