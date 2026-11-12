<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * A forced read of `.loupe.yaml` failed on every attempt, so the stored rows of the repository
 * may be stale. A listener must be idempotent and read the current rows itself.
 */
final readonly class RepositoryWorkflowSettingsUnread
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $repositoryId,
    ) {
    }
}
