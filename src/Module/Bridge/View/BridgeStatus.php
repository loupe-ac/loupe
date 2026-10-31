<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** What the server knows about one bridge of an owner at one moment. */
final readonly class BridgeStatus
{
    public function __construct(
        /** Null when no heartbeat from this owner's bridge has reached the server. */
        public ?\DateTimeImmutable $lastSeenAt,
        public \DateTimeImmutable $checkedAt,
        public bool $quiet,
        /** True for a quiet bridge whose login holds no refresh token that can still work. */
        public bool $loginExpired = false,
    ) {
    }
}
