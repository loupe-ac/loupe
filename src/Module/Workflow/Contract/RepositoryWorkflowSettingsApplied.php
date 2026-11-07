<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * A read of `.loupe.yaml` finished for one repository and stored its rows. It fires inside the transaction
 * of the read, also when no row changed, so a listener must be idempotent and read the current rows itself.
 */
final readonly class RepositoryWorkflowSettingsApplied
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $repositoryId,
    ) {
    }
}
