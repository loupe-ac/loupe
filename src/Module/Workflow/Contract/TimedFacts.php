<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * Facts whose answer changes with the clock alone. The provider computes the time in build(), from the rows and the
 * clock of its answer, and leaves it out of the fingerprint, so a new time alone is no change.
 */
interface TimedFacts
{
    /** @return ?\DateTimeImmutable the first instant of the next answer, or null when time alone never changes it */
    public function changesAt(): ?\DateTimeImmutable;
}
