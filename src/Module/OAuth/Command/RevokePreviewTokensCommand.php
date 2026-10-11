<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Names the tokens to revoke in one of three ways: a person's label on a
 * project, one access token id, or a card id. Set the fields of exactly one.
 */
final readonly class RevokePreviewTokensCommand
{
    public function __construct(
        public ?User $owner = null,
        /** An id or a slug. */
        public ?string $handle = null,
        public ?string $label = null,
        public ?string $accessTokenId = null,
        public ?Uuid $cardId = null,
    ) {
    }
}
