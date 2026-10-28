<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** A page outside Workflow asks this port which template a project runs, so it can name the workflow. */
interface WorkflowKeys
{
    /** @return string|null the template key of the stored copy, null for a project bound to no template */
    public function forProject(Uuid $projectId): ?string;
}
