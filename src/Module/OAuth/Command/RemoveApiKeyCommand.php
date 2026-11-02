<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Entity\ApiKey;

final readonly class RemoveApiKeyCommand
{
    public function __construct(
        public ApiKey $key,
    ) {
    }
}
