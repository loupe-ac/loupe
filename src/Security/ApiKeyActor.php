<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Uid\Uuid;

/** The API key behind the current request, as a history row names it. */
final readonly class ApiKeyActor
{
    public function __construct(
        public Uuid $keyId,
        public string $keyName,
    ) {
    }
}
