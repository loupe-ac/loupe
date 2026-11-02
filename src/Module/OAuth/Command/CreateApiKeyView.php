<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Entity\ApiKey;

/** The new key row and the whole key. This is the only place the whole key exists. */
final readonly class CreateApiKeyView
{
    public function __construct(
        public ApiKey $key,
        public string $secret,
    ) {
    }
}
