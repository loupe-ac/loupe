<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;

final readonly class ReissueBetaInviteCommand
{
    public function __construct(
        public BetaInvite $invite,
        public User $createdBy,
    ) {
    }
}
