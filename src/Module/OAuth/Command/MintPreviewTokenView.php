<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class MintPreviewTokenView
{
    public function __construct(
        /** @var non-empty-string */
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
