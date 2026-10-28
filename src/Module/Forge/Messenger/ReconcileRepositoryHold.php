<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/**
 * Queued in the transaction that changes the hold summary, so the change and
 * its reconciliation commit together, and a failed listener retries.
 */
final readonly class ReconcileRepositoryHold
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
