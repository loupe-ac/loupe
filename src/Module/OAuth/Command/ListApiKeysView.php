<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Entity\ApiKey;

final readonly class ListApiKeysView
{
    /** @param list<ApiKey> $keys newest first */
    public function __construct(
        public array $keys,
    ) {
    }
}
