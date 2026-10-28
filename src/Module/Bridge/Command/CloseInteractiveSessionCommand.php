<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/** Closes every open interactive run of one session in the projects of its owner. */
final readonly class CloseInteractiveSessionCommand
{
    public function __construct(
        public User $owner,
        public Uuid $sessionId,
    ) {
    }
}
