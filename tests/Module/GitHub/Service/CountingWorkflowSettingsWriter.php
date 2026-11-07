<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Workflow\Contract\RepositorySettingsReadResult;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use Symfony\Component\Uid\Uuid;

/** Passes every call to the real writer and counts the writes. */
final class CountingWorkflowSettingsWriter implements RepositoryWorkflowSettingsWriter
{
    public int $applied = 0;

    public function __construct(
        private readonly RepositoryWorkflowSettingsWriter $inner,
    ) {
    }

    #[\Override]
    public function isBlobUnchanged(Uuid $repositoryId, string $blobSha): bool
    {
        return $this->inner->isBlobUnchanged($repositoryId, $blobSha);
    }

    #[\Override]
    public function apply(Uuid $projectId, Uuid $repositoryId, ?string $blobSha, string $headSha, array $fileSettings, bool $fileUsable): RepositorySettingsReadResult
    {
        ++$this->applied;

        return $this->inner->apply($projectId, $repositoryId, $blobSha, $headSha, $fileSettings, $fileUsable);
    }
}
