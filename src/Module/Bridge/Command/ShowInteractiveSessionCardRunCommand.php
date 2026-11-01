<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ShowInteractiveSessionCardRunCommand
{
    /** @param non-empty-string $projectHandle the id or the slug of a project */
    public function __construct(
        public User $owner,
        public string $projectHandle,
        public Uuid $sessionId,
    ) {
    }
}
