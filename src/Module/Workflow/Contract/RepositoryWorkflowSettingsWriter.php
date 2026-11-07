<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Stores what the `workflow:` map of `.loupe.yaml` says for one repository. GitHub calls it after a read. */
interface RepositoryWorkflowSettingsWriter
{
    /** Whether the stored rows of the repository come from this blob, so a read of the same blob changes nothing. */
    public function isBlobUnchanged(Uuid $repositoryId, string $blobSha): bool;

    /**
     * Replaces the file rows of the repository, and deletes the stored row of each known key that the file sets. It flushes inside the transaction of the caller.
     *
     * @param array<string, mixed> $fileSettings the raw `workflow:` map, or an empty array when the file has none
     * @param bool                 $fileUsable   false when the file is missing or names another project, which removes every row and deletes no stored row
     */
    public function apply(Uuid $projectId, Uuid $repositoryId, ?string $blobSha, string $headSha, array $fileSettings, bool $fileUsable): RepositorySettingsReadResult;
}
