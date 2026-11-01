<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ClaimWorkRequestCommand
{
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public Uuid $workRequestId,
        /** The account the bridge chose for the request. Null from a bridge that sends none, which the claim refuses. */
        public ?string $account = null,
    ) {
    }
}
