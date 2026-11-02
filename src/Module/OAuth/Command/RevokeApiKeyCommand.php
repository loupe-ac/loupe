<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;

final readonly class RevokeApiKeyCommand
{
    public function __construct(
        public ApiKey $key,
        public User $actor,
    ) {
    }
}
