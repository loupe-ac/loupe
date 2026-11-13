<?php

declare(strict_types=1);

namespace App\Module\GitHub\Messenger;

/**
 * Asks for one read of `.loupe.yaml` in one repository. It names no commit,
 * because the handler reads the head that is current when it holds the lock.
 * A forced read reconciles also when the file is unchanged, and tries again when GitHub fails.
 */
final readonly class ReadRepositoryWorkflowSettings
{
    public function __construct(
        public string $repositoryId,
        public bool $force = false,
        public int $attempt = 1,
    ) {
    }
}
