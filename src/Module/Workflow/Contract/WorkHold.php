<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Why a project holds its new work: the repositories that block it, and the count of their blocking gaps. */
final readonly class WorkHold
{
    /** @param list<string> $repositories the path of each blocking repository */
    public function __construct(
        public array $repositories,
        public int $blockingGaps,
    ) {
    }
}
