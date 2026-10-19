<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Board asks this port to change the approval rule in the template copy of one project. */
interface ApprovalRuleWriter
{
    /** Returns false, and writes nothing, when the project has no workflow. */
    public function setCoversConflictResolution(Uuid $projectId, bool $value): bool;
}
