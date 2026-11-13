<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Board asks this port to change the approval rule in the template copy of one project. */
interface ApprovalRuleWriter
{
    /**
     * Returns null, and writes nothing, when the project has no workflow.
     * Returns true when it changed the stored value, and false when the stored value already equals the one given.
     * The caller holds a lock that serializes writers, and the comparison reads the committed value.
     * Announces nothing: the caller dispatches ApprovalRuleChanged after its transaction commits.
     */
    public function setCoversConflictResolution(Uuid $projectId, bool $value): ?bool;
}
