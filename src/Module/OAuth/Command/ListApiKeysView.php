<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Entity\ApiKey;

final readonly class ListApiKeysView
{
    /** @param list<ApiKey> $keys newest first, without the revoked ones */
    public function __construct(
        public array $keys,
        public \DateTimeImmutable $now,
    ) {
    }
}
