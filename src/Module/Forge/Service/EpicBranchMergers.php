<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class EpicBranchMergers
{
    /** @param iterable<EpicBranchMerger> $mergers */
    public function __construct(
        #[AutowireIterator('app.epic_branch_merger')]
        private iterable $mergers,
    ) {
    }

    public function for(string $forge): ?EpicBranchMerger
    {
        foreach ($this->mergers as $merger) {
            if ($merger->supports($forge)) {
                return $merger;
            }
        }

        return null;
    }
}
