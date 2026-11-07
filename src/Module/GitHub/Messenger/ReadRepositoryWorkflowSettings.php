<?php

declare(strict_types=1);

namespace App\Module\GitHub\Messenger;

/**
 * Asks for one read of `.loupe.yaml` in one repository. It names no commit,
 * because the handler reads the head that is current when it holds the lock.
 */
final readonly class ReadRepositoryWorkflowSettings
{
    public function __construct(
        public string $repositoryId,
    ) {
    }
}
