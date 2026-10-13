<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A merge, a sync or a base change that Loupe asked the forge for. It stops counting at its expiry, when Loupe would ask again. */
final readonly class ForgeRequest
{
    public function __construct(
        public \DateTimeImmutable $requestedAt,
        public \DateTimeImmutable $expiresAt,
        public bool $merge = false,
    ) {
    }

    public function pendingAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }
}
