<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The hold summary of a project changed. A queued reconciliation dispatches it
 * after the commit, and again after a failed listener, so a listener must be
 * idempotent. It carries the project id alone: two events can arrive in the
 * opposite order of their commits, so a listener reads the current summary itself.
 */
final readonly class ForgeRepositoryCheckChanged
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
