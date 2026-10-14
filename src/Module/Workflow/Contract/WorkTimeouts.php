<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Bridge asks this port how long a work request waits for a bridge, and the workflow template of the project says. */
interface WorkTimeouts
{
    /** @return positive-int the minutes an open request waits for a bridge before it expires */
    public function minutes(Uuid $projectId): int;
}
