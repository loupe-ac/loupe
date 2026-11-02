<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKeyExpiry;

final readonly class CreateApiKeyCommand
{
    public function __construct(
        public User $owner,
        public string $name,
        public ApiKeyExpiry $expiry,
    ) {
    }
}
