<?php

declare(strict_types=1);

namespace App\Module\OAuth\Event;

use App\Module\OAuth\Entity\ApiKey;

/** Dispatched after the new key is committed. The event never carries the key itself. */
final readonly class ApiKeyCreated
{
    public function __construct(
        public ApiKey $key,
    ) {
    }
}
