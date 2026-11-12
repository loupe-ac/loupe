<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A check of one repository completed: the read succeeded and its result was accepted.
 * It fires also when the gaps did not change, so a listener must be idempotent and read the current summary itself.
 */
final readonly class ForgeRepositoryCheckEnded
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $repositoryId,
    ) {
    }
}
