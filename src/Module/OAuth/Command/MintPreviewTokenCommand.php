<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class MintPreviewTokenCommand
{
    public function __construct(
        public User $owner,
        /** An id or a slug. */
        public string $handle,
        public string $label,
        public ?Uuid $cardId = null,
    ) {
    }
}
