<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\WorkflowFileReads;

/** Records the reads that a test queued for the `fake-init` forge. */
final class FakeWorkflowFileReads implements WorkflowFileReads
{
    /** @var list<array{string, bool}> the path and the force flag of each queued read */
    public array $queued = [];

    #[\Override]
    public function supports(string $forge): bool
    {
        return FakeInitPullRequestReader::FORGE === $forge;
    }

    #[\Override]
    public function queue(ForgeRepository $repository, bool $force): void
    {
        $this->queued[] = [$repository->path, $force];
    }
}
