<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A condition that tells a marker what it saw. A condition without it gives a marker no label values and no start time. */
interface MarkerEvidence
{
    /** @param array<string, mixed> $params */
    public function evidence(Facts $facts, array $params): ?Evidence;
}
