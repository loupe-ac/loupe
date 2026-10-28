<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow;

use App\Module\Workflow\Contract\BlockingRepository;
use App\Module\Workflow\Contract\WorkHold;
use App\Module\Workflow\Contract\WorkHolds;
use Symfony\Component\Uid\Uuid;

/** A work hold that a test switches on and off. */
final class FakeWorkHolds implements WorkHolds
{
    public bool $held = false;

    /** The hold to return. A null value falls back to a one-repository hold while $held is true. */
    public ?WorkHold $hold = null;

    public int $reads = 0;

    #[\Override]
    public function heldProject(Uuid $projectId): ?WorkHold
    {
        ++$this->reads;

        return $this->hold ?? ($this->held ? new WorkHold([new BlockingRepository('0197c8a0-0000-7000-8000-000000000001', 'acme/widgets', 1)]) : null);
    }
}
