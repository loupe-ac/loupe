<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReader;
use App\Module\Project\Entity\Project;

/** A branch reader whose readable rows and answer a test sets. */
final class StubBranchReader implements BranchReader
{
    /** @var array<string, ForgeRepository> */
    public array $readable = [];

    public BranchPresence $answer = BranchPresence::Exists;

    /** @var ?\Closure(): void */
    public ?\Closure $onPresence = null;

    public int $asked = 0;

    public function makeReadable(ForgeRepository $row): void
    {
        $this->readable[$row->forge.':'.mb_strtolower($row->path)] = $row;
    }

    #[\Override]
    public function readableRepository(Project $project, string $remoteUrl): ?ForgeRepository
    {
        return null;
    }

    #[\Override]
    public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository
    {
        return $this->readable[$forge.':'.mb_strtolower($path)] ?? null;
    }

    #[\Override]
    public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence
    {
        ++$this->asked;
        if (null !== $this->onPresence) {
            ($this->onPresence)();
        }

        return $this->answer;
    }
}
