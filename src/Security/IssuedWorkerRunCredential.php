<?php

declare(strict_types=1);

namespace App\Security;

/** A run token as the bridge receives it. The token shows once, at the mint. */
final readonly class IssuedWorkerRunCredential
{
    /** @param non-empty-string $token */
    public function __construct(
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
