<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Why a project holds its new work: the repositories that block it, and the count of their blocking gaps. */
final readonly class WorkHold
{
    public int $blockingGaps;

    /** @param list<BlockingRepository> $repositories */
    public function __construct(
        public array $repositories,
    ) {
        $this->blockingGaps = array_sum(array_map(static fn (BlockingRepository $repository): int => $repository->blockingGaps, $repositories));
    }
}
