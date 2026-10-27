<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow;

use App\Module\Workflow\Contract\WorkHold;
use App\Module\Workflow\Contract\WorkHolds;
use Symfony\Component\Uid\Uuid;

/** A work hold that a test switches on and off. */
final class FakeWorkHolds implements WorkHolds
{
    public bool $held = false;

    public int $reads = 0;

    #[\Override]
    public function heldProject(Uuid $projectId): ?WorkHold
    {
        ++$this->reads;

        return $this->held ? new WorkHold(['acme/widgets'], 1) : null;
    }
}
