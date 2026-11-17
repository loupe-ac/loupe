<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class IssueWorkerRunCredentialCommand
{
    public function __construct(
        public User $owner,
        /** An id or a slug. */
        public string $handle,
        public Uuid $bridgeId,
        public Uuid $runKey,
        public Uuid $sessionId,
    ) {
    }
}
