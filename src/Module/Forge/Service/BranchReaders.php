<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Asks every forge reader. A repository belongs to one forge, so at most one reader answers. */
final readonly class BranchReaders
{
    /** @param iterable<BranchReader> $readers */
    public function __construct(
        #[AutowireIterator('app.branch_reader')]
        private iterable $readers,
    ) {
    }

    public function readableRepository(Project $project, string $remoteUrl): ?ForgeRepository
    {
        foreach ($this->readers as $reader) {
            $row = $reader->readableRepository($project, $remoteUrl);
            if (null !== $row) {
                return $row;
            }
        }

        return null;
    }

    public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository
    {
        foreach ($this->readers as $reader) {
            $row = $reader->readableRepositoryAt($project, $forge, $path);
            if (null !== $row) {
                return $row;
            }
        }

        return null;
    }

    public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence
    {
        foreach ($this->readers as $reader) {
            $presence = $reader->presence($project, $repository, $branch);
            if (BranchPresence::Unreadable !== $presence) {
                return $presence;
            }
        }

        return BranchPresence::Unreadable;
    }
}
