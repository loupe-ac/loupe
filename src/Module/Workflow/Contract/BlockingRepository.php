<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** One repository that blocks the new work of a project, with the count of its blocking gaps. */
final readonly class BlockingRepository
{
    public function __construct(
        /** The RFC 4122 id of the repository. */
        public string $id,
        public string $path,
        public int $blockingGaps,
    ) {
    }
}
