<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A condition whose answer changes with the clock. */
interface TimedCondition
{
    /**
     * @param array<string, mixed> $params
     *
     * @return ?\DateTimeImmutable the time the answer next changes, or null when time alone never changes it
     */
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable;
}
