<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Asks every forge reader. A remote URL belongs to one forge, so at most one reader answers true. */
final readonly class BranchReaders
{
    /** @param iterable<BranchReader> $readers */
    public function __construct(
        #[AutowireIterator('app.branch_reader')]
        private iterable $readers,
    ) {
    }

    public function exists(Project $project, string $remoteUrl, string $branch): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->exists($project, $remoteUrl, $branch)) {
                return true;
            }
        }

        return false;
    }
}
