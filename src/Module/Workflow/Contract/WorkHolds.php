<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Tells whether a project holds its new work requests. Forge implements it. */
interface WorkHolds
{
    /**
     * Null when the project holds nothing. The read takes a key share lock on the project row,
     * so call it inside the transaction that acts on the answer.
     */
    public function heldProject(Uuid $projectId): ?WorkHold;
}
